import base64
import json
import logging
import os
import random
import tempfile
import threading
import traceback
import uuid
from collections.abc import Iterator
from pathlib import Path
from typing import Any

import torch
from flask import Flask, jsonify, request
from PIL import Image, ImageOps

from ltx_core.components.diffusion_steps import EulerDiffusionStep
from ltx_core.components.noisers import GaussianNoiser
from ltx_core.components.protocols import DiffusionStepProtocol
from ltx_core.loader import LoraPathStrengthAndSDOps
from ltx_core.model.audio_vae import decode_audio as vae_decode_audio
from ltx_core.model.upsampler import upsample_video
from ltx_core.model.video_vae import TilingConfig, get_video_chunks_number
from ltx_core.model.video_vae import decode_video as vae_decode_video
from ltx_core.text_encoders.gemma import encode_text
from ltx_core.types import LatentState, VideoPixelShape
from ltx_pipelines.utils import ModelLedger
from ltx_pipelines.utils.args import default_2_stage_distilled_arg_parser
from ltx_pipelines.utils.constants import (
    AUDIO_SAMPLE_RATE,
    DISTILLED_SIGMA_VALUES,
    STAGE_2_DISTILLED_SIGMA_VALUES,
)
from ltx_pipelines.utils.helpers import (
    assert_resolution,
    cleanup_memory,
    denoise_audio_video,
    euler_denoising_loop,
    generate_enhanced_prompt,
    get_device,
    image_conditionings_by_replacing_latent,
    simple_denoising_func,
)
from ltx_pipelines.utils.media_io import encode_video
from ltx_pipelines.utils.types import PipelineComponents

device = get_device()


class DistilledPipeline:
    def __init__(
            self,
            checkpoint_path: str,
            gemma_root: str,
            spatial_upsampler_path: str,
            loras: list[LoraPathStrengthAndSDOps],
            device: torch.device = device,
            fp8transformer: bool = False,
    ):
        self.device = device
        self.dtype = torch.bfloat16

        self.model_ledger = ModelLedger(
            dtype=self.dtype,
            device=device,
            checkpoint_path=checkpoint_path,
            spatial_upsampler_path=spatial_upsampler_path,
            gemma_root_path=gemma_root,
            loras=loras,
            fp8transformer=fp8transformer,
        )

        self.pipeline_components = PipelineComponents(dtype=self.dtype, device=device)

    def __call__(
            self,
            prompt: str,
            seed: int,
            height: int,
            width: int,
            num_frames: int,
            frame_rate: float,
            images: list[tuple[str, int, float]],
            tiling_config: TilingConfig | None = None,
            enhance_prompt: bool = False,
    ) -> tuple[Iterator[torch.Tensor], torch.Tensor]:
        assert_resolution(height=height, width=width, is_two_stage=True)

        generator = torch.Generator(device=self.device).manual_seed(seed)
        noiser = GaussianNoiser(generator=generator)
        stepper = EulerDiffusionStep()
        dtype = torch.bfloat16

        text_encoder = self.model_ledger.text_encoder()
        if enhance_prompt:
            prompt = generate_enhanced_prompt(text_encoder, prompt, images[0][0] if len(images) > 0 else None)
        context_p = encode_text(text_encoder, prompts=[prompt])[0]
        video_context, audio_context = context_p

        if torch.cuda.is_available():
            torch.cuda.synchronize()
        del text_encoder
        # cleanup_memory()

        video_encoder = self.model_ledger.video_encoder()
        transformer = self.model_ledger.transformer()
        stage_1_sigmas = torch.Tensor(DISTILLED_SIGMA_VALUES).to(self.device)

        def denoising_loop(
                sigmas: torch.Tensor, video_state: LatentState, audio_state: LatentState, stepper_: DiffusionStepProtocol
        ) -> tuple[LatentState, LatentState]:
            return euler_denoising_loop(
                sigmas=sigmas,
                video_state=video_state,
                audio_state=audio_state,
                stepper=stepper_,
                denoise_fn=simple_denoising_func(
                    video_context=video_context,
                    audio_context=audio_context,
                    transformer=transformer,
                ),
            )

        stage_1_output_shape = VideoPixelShape(
            batch=1,
            frames=num_frames,
            width=width // 2,
            height=height // 2,
            fps=frame_rate,
        )
        stage_1_conditionings = image_conditionings_by_replacing_latent(
            images=images,
            height=stage_1_output_shape.height,
            width=stage_1_output_shape.width,
            video_encoder=video_encoder,
            dtype=dtype,
            device=self.device,
        )

        video_state, audio_state = denoise_audio_video(
            output_shape=stage_1_output_shape,
            conditionings=stage_1_conditionings,
            noiser=noiser,
            sigmas=stage_1_sigmas,
            stepper=stepper,
            denoising_loop_fn=denoising_loop,
            components=self.pipeline_components,
            dtype=dtype,
            device=self.device,
        )

        upscaled_video_latent = upsample_video(
            latent=video_state.latent[:1],
            video_encoder=video_encoder,
            upsampler=self.model_ledger.spatial_upsampler(),
        )

        if torch.cuda.is_available():
            torch.cuda.synchronize()
        # cleanup_memory()

        stage_2_sigmas = torch.Tensor(STAGE_2_DISTILLED_SIGMA_VALUES).to(self.device)
        stage_2_output_shape = VideoPixelShape(batch=1, frames=num_frames, width=width, height=height, fps=frame_rate)
        stage_2_conditionings = image_conditionings_by_replacing_latent(
            images=images,
            height=stage_2_output_shape.height,
            width=stage_2_output_shape.width,
            video_encoder=video_encoder,
            dtype=dtype,
            device=self.device,
        )
        video_state, audio_state = denoise_audio_video(
            output_shape=stage_2_output_shape,
            conditionings=stage_2_conditionings,
            noiser=noiser,
            sigmas=stage_2_sigmas,
            stepper=stepper,
            denoising_loop_fn=denoising_loop,
            components=self.pipeline_components,
            dtype=dtype,
            device=self.device,
            noise_scale=stage_2_sigmas[0],
            initial_video_latent=upscaled_video_latent,
            initial_audio_latent=audio_state.latent,
        )

        if torch.cuda.is_available():
            torch.cuda.synchronize()
        del transformer
        del video_encoder
        cleanup_memory()

        decoded_video = vae_decode_video(video_state.latent, self.model_ledger.video_decoder(), tiling_config)
        decoded_audio = vae_decode_audio(audio_state.latent, self.model_ledger.audio_decoder(), self.model_ledger.vocoder())
        return decoded_video, decoded_audio

def _snap_int_to_multiple(value: int, multiple: int, mode: str = "floor") -> int:
    if multiple <= 0:
        raise ValueError("multiple must be > 0")

    value_int = int(value)
    if mode == "ceil":
        return ((value_int + multiple - 1) // multiple) * multiple
    if mode == "nearest":
        lower = (value_int // multiple) * multiple
        upper = ((value_int + multiple - 1) // multiple) * multiple
        return lower if (value_int - lower) <= (upper - value_int) else upper
    return (value_int // multiple) * multiple  # floor


def _coerce_two_stage_resolution(height: int, width: int, multiple: int = 64, mode: str = "floor") -> tuple[int, int]:
    coerced_height = _snap_int_to_multiple(height, multiple=multiple, mode=mode)
    coerced_width = _snap_int_to_multiple(width, multiple=multiple, mode=mode)

    if coerced_height < multiple:
        coerced_height = multiple
    if coerced_width < multiple:
        coerced_width = multiple

    return coerced_height, coerced_width

def _strip_data_uri_prefix(base64_text: str) -> str:
    candidate = base64_text.strip()
    if candidate.startswith("data:") and "," in candidate:
        return candidate.split(",", 1)[1]
    return candidate


def _decode_base64_bytes(base64_text: str) -> bytes:
    return base64.b64decode(_strip_data_uri_prefix(base64_text))


def _coerce_bool(value: Any, default_value: bool) -> bool:
    if value is None:
        return default_value
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return bool(value)
    if isinstance(value, str):
        lowered = value.strip().lower()
        if lowered in {"1", "true", "t", "yes", "y", "on"}:
            return True
        if lowered in {"0", "false", "f", "no", "n", "off"}:
            return False
    return default_value


def _read_source_image_size(image_path: str) -> tuple[int, int]:
    with Image.open(image_path) as opened_image:
        oriented_image = ImageOps.exif_transpose(opened_image)
        width, height = oriented_image.size
        return int(width), int(height)


def _normalize_image_conditioning_item(item: Any, temp_dir: str) -> tuple[str, int, float]:
    if isinstance(item, dict):
        frame_index = int(item.get("frame", item.get("frame_index", 0)))
        strength = float(item.get("strength", item.get("weight", 1.0)))

        if "path" in item:
            image_path = str(item["path"])
            return (image_path, frame_index, strength)

        if "image" in item:
            image_bytes = _decode_base64_bytes(str(item["image"]))
            image_path = os.path.join(temp_dir, f"img_{uuid.uuid4().hex}.png")
            with open(image_path, "wb") as file_handle:
                file_handle.write(image_bytes)
            return (image_path, frame_index, strength)

        raise ValueError('Each images[] item must have either "path" or "image".')

    if isinstance(item, (list, tuple)) and len(item) >= 3:
        first = item[0]
        frame_index = int(item[1])
        strength = float(item[2])

        if isinstance(first, str) and os.path.exists(first):
            return (first, frame_index, strength)

        image_bytes = _decode_base64_bytes(str(first))
        image_path = os.path.join(temp_dir, f"img_{uuid.uuid4().hex}.png")
        with open(image_path, "wb") as file_handle:
            file_handle.write(image_bytes)
        return (image_path, frame_index, strength)

    raise ValueError('images[] must be a list of dicts or [image_or_path, frame, strength].')


def _build_images_from_request(data: dict[str, Any], temp_dir: str, fallback_images: list[tuple[str, int, float]]) -> list[tuple[str, int, float]]:
    request_images: list[tuple[str, int, float]] = []

    init_images = data.get("init_images")
    if init_images:
        if not isinstance(init_images, list) or len(init_images) == 0:
            raise ValueError("init_images must be a non-empty list of base64 strings.")
        init_image_bytes = _decode_base64_bytes(str(init_images[0]))
        init_image_path = os.path.join(temp_dir, f"init_{uuid.uuid4().hex}.png")
        with open(init_image_path, "wb") as file_handle:
            file_handle.write(init_image_bytes)
        request_images.append((init_image_path, 0, 1.0))

    explicit_images = data.get("images")
    if explicit_images is not None:
        if not isinstance(explicit_images, list):
            raise ValueError("images must be a list.")
        for item in explicit_images:
            request_images.append(_normalize_image_conditioning_item(item, temp_dir))
        return request_images

    return request_images if request_images else list(fallback_images)


def _encode_video_to_base64_mp4(
        video: Iterator[torch.Tensor],
        audio: torch.Tensor,
        fps: float,
        num_frames: int,
        tiling_config: TilingConfig,
) -> str:
    video_chunks_number = get_video_chunks_number(num_frames, tiling_config)
    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as tmp_file:
            tmp_path = tmp_file.name

        encode_video(
            video=video,
            fps=fps,
            audio=audio,
            audio_sample_rate=AUDIO_SAMPLE_RATE,
            output_path=tmp_path,
            video_chunks_number=video_chunks_number,
        )

        with open(tmp_path, "rb") as file_handle:
            return base64.b64encode(file_handle.read()).decode("utf-8")
    finally:
        if tmp_path and os.path.exists(tmp_path):
            os.remove(tmp_path)


@torch.inference_mode()
def _run_generation(
        pipeline: DistilledPipeline,
        tiling_config: TilingConfig,
        prompt: str,
        seed: int,
        height: int,
        width: int,
        num_frames: int,
        frame_rate: float,
        images: list[tuple[str, int, float]],
        enhance_prompt: bool,
) -> str:
    video, audio = pipeline(
        prompt=prompt,
        seed=seed,
        height=height,
        width=width,
        num_frames=num_frames,
        frame_rate=frame_rate,
        images=images,
        tiling_config=tiling_config,
        enhance_prompt=enhance_prompt,
    )
    return _encode_video_to_base64_mp4(video=video, audio=audio, fps=frame_rate, num_frames=num_frames, tiling_config=tiling_config)


def main() -> None:
    logging.getLogger().setLevel(logging.INFO)

    parser = default_2_stage_distilled_arg_parser()
    parser.add_argument("--host", type=str, default="0.0.0.0")
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--max_concurrent_requests", type=int, default=1)
    args = parser.parse_args()

    app = Flask(__name__)
    request_semaphore = threading.Semaphore(int(args.max_concurrent_requests))
    tiling_config = TilingConfig.default()

    pipeline = DistilledPipeline(
        checkpoint_path=args.checkpoint_path,
        spatial_upsampler_path=args.spatial_upsampler_path,
        gemma_root=args.gemma_root,
        loras=args.lora,
        fp8transformer=args.enable_fp8,
    )

    total_inference_steps = len(DISTILLED_SIGMA_VALUES) + len(STAGE_2_DISTILLED_SIGMA_VALUES)
    checkpoint_label = Path(args.checkpoint_path).stem

    @app.route("/txt2vid", methods=["POST"])
    def txt2vid() -> Any:
        data = request.get_json(silent=True) or {}
        logging.info("Got new /txt2vid request")

        prompt = str(data.get("prompt"))
        seed = int(data.get("seed", random.randint(1, 99999999999999)))
        height = int(data.get("height", args.height))
        width = int(data.get("width", args.width))
        num_frames = int(data.get("num_frames", args.num_frames))
        frame_rate = float(data.get("frame_rate", args.frame_rate))
        enhance_prompt = _coerce_bool(data.get("enhance_prompt"), bool(args.enhance_prompt))

        if num_frames > 480:
            return jsonify({"error": "Too many frames"}), 400

        coerced_height, coerced_width = _coerce_two_stage_resolution(height=height, width=width, multiple=64, mode="floor")
        if (coerced_height, coerced_width) != (height, width):
            logging.info("Coerced txt2vid resolution from %sx%s to %sx%s", width, height, coerced_width, coerced_height)
        height, width = coerced_height, coerced_width

        request_semaphore.acquire()
        try:
            with tempfile.TemporaryDirectory() as temp_dir:
                images = _build_images_from_request(data, temp_dir, fallback_images=args.images)
                video_base64 = _run_generation(
                    pipeline=pipeline,
                    tiling_config=tiling_config,
                    prompt=prompt,
                    seed=seed,
                    height=height,
                    width=width,
                    num_frames=num_frames,
                    frame_rate=frame_rate,
                    images=images,
                    enhance_prompt=enhance_prompt,
                )
        except ValueError as exc:
            return jsonify({"error": str(exc)}), 400
        except Exception as exc:
            logging.error(str(exc))
            logging.error(traceback.format_exc())
            return jsonify({"error": str(exc)}), 500
        finally:
            request_semaphore.release()

        infotext = f"{prompt}\nSeed: {seed}, Frames: {num_frames}, FPS: {frame_rate}, Model: {checkpoint_label}, Prompt enhanced: {enhance_prompt}"
        return jsonify(
            {
                "videos": [video_base64],
                "parameters": {
                    "width": width,
                    "height": height,
                    "num_frames": num_frames,
                    "seed": seed,
                    "frame_rate": frame_rate,
                },
                "info": json.dumps({"infotexts": [infotext]}),
            }
        )

    @app.route("/img2vid", methods=["POST"])
    def img2vid() -> Any:
        data = request.get_json(silent=True) or {}
        logging.info("Got new /img2vid request")

        prompt = str(data.get("prompt"))
        seed = int(data.get("seed", random.randint(1, 99999999999999)))
        num_frames = int(data.get("num_frames", args.num_frames))
        frame_rate = float(data.get("frame_rate", args.frame_rate))
        enhance_prompt = _coerce_bool(data.get("enhance_prompt"), bool(args.enhance_prompt))

        if num_frames > 480:
            return jsonify({"error": "Too many frames"}), 400

        request_semaphore.acquire()
        try:
            with tempfile.TemporaryDirectory() as temp_dir:
                images = _build_images_from_request(data, temp_dir, fallback_images=[])
                if len(images) == 0:
                    return jsonify({"error": "No image provided. Send init_images[0] (base64) or images[]"}), 400

                source_width, source_height = _read_source_image_size(images[0][0])
                width, height = source_width, source_height

                coerced_height, coerced_width = _coerce_two_stage_resolution(height=height, width=width, multiple=64, mode="floor")
                if (coerced_height, coerced_width) != (height, width):
                    logging.info("Coerced img2vid resolution from %sx%s to %sx%s", width, height, coerced_width, coerced_height)
                height, width = coerced_height, coerced_width

                logging.info("Using img2vid resolution: width=%s height=%s", width, height)

                video_base64 = _run_generation(
                    pipeline=pipeline,
                    tiling_config=tiling_config,
                    prompt=prompt,
                    seed=seed,
                    height=height,
                    width=width,
                    num_frames=num_frames,
                    frame_rate=frame_rate,
                    images=images,
                    enhance_prompt=enhance_prompt,
                )
        except ValueError as exc:
            return jsonify({"error": str(exc)}), 400
        except Exception as exc:
            logging.error(str(exc))
            logging.error(traceback.format_exc())
            return jsonify({"error": str(exc)}), 500
        finally:
            request_semaphore.release()

        infotext = f"{prompt}\nSeed: {seed}, Frames: {num_frames}, FPS: {frame_rate}, Model: {checkpoint_label}, Prompt enhanced: {enhance_prompt}"
        return jsonify(
            {
                "videos": [video_base64],
                "parameters": {
                    "width": width,
                    "height": height,
                    "num_frames": num_frames,
                    "seed": seed,
                    "frame_rate": frame_rate,
                },
                "info": json.dumps({"infotexts": [infotext]}),
            }
        )

    app.run(host=str(args.host), port=int(args.port), threaded=True)


if __name__ == "__main__":
    main()
