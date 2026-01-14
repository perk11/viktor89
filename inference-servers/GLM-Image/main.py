import argparse
import base64
import json
import random
import sys
import threading
from io import BytesIO
from pathlib import Path
from typing import List, Optional, Tuple

import torch
from PIL import Image
from diffusers.pipelines.glm_image import GlmImagePipeline
from flask import Flask, jsonify, request

# Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))
from util.image_to_json import image_to_json_response

app = Flask(__name__)

parser = argparse.ArgumentParser(description="Inference server for GLM-Image (txt2img + img2img)")
parser.add_argument("--port", type=int, required=True, help="port to listen on")
args = parser.parse_args()

torch_dtype = torch.bfloat16
device = "cuda"

sem = threading.Semaphore()

pipe = GlmImagePipeline.from_pretrained("zai-org/GLM-Image", torch_dtype=torch_dtype, device_map=device)


def _round_to_multiple(value: int, multiple: int) -> int:
    if value <= 0:
        return multiple
    return max(multiple, int(round(value / multiple)) * multiple)


def normalize_size(width: int, height: int, multiple: int = 32) -> Tuple[int, int]:
    width = _round_to_multiple(int(width), multiple)
    height = _round_to_multiple(int(height), multiple)
    return width, height


def base64_to_pil(image_base64: str) -> Image.Image:
    image_bytes = base64.b64decode(image_base64)
    return Image.open(BytesIO(image_bytes)).convert("RGB")


def build_info_text(
        prompt: str,
        steps: int,
        seed: int,
        width: int,
        height: int,
) -> str:
    return (
        f"{prompt}\n"
        f"Steps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: GLM-Image"
    )


def _resize_init_images(init_images: List[Image.Image], width: int, height: int) -> List[Image.Image]:
    resized: List[Image.Image] = []
    for img in init_images:
        if img.size != (width, height):
            resized.append(img.resize((width, height), resample=Image.LANCZOS))
        else:
            resized.append(img)
    return resized

def infer_image_to_json(
        prompt: str,
        steps: int,
        seed: int,
        width: int,
        height: int,
        mode: str,
        init_images: Optional[List[Image.Image]] = None,
):
    sem.acquire()
    try:
        if mode == "txt2img":
            output_image = pipe(
                prompt=prompt,
                height=height,
                width=width,
                num_inference_steps=steps,
                guidance_scale=1.5,
                generator=torch.Generator(device=device).manual_seed(seed),
            ).images[0]
            infotext = build_info_text(prompt, steps, seed, width, height)
            return image_to_json_response(output_image, infotext)

        if mode == "img2img":
            if not init_images:
                return jsonify({"error": "img2img requires init_images"}), 400

            resized_images = _resize_init_images(init_images, width, height)
            output_image = pipe(
                prompt=prompt,
                image=resized_images,
                height=height,
                width=width,
                num_inference_steps=steps,
                guidance_scale=1.5,
                generator=torch.Generator(device=device).manual_seed(seed)
            ).images[0]
            infotext = build_info_text(prompt, steps, seed, width, height)

            return image_to_json_response(output_image, infotext)


        return jsonify({"error": f"unknown mode: {mode}"}), 400

    except Exception as e:
        return jsonify({"error": str(e)}), 500
    finally:
        sem.release()
        if device.startswith("cuda") and torch.cuda.is_available():
            torch.cuda.empty_cache()
            torch.cuda.ipc_collect()

@app.route("/sdapi/v1/txt2img", methods=["POST"])
def txt2img():
    data = request.get_json(force=True, silent=False)
    print(data, flush=True)
    prompt = data.get("prompt")
    if not prompt:
        return jsonify({"error": "missing prompt"}), 400

    seed = int(data.get("seed", random.randint(1, 2 ** 32 - 1)))
    steps = int(data.get("steps", data.get("num_inference_steps", 50)))

    width = int(data.get("width", 1024))
    height = int(data.get("height", 1024))
    width, height = normalize_size(width, height, multiple=32)

    return infer_image_to_json(
        prompt=prompt,
        steps=steps,
        seed=seed,
        width=width,
        height=height,
        mode="txt2img",
    )

@app.route("/sdapi/v1/img2img", methods=["POST"])
def img2img():
    data = request.get_json(force=True, silent=False)
    prompt = data.get("prompt")
    data_for_print = data.copy()
    data_for_print['init_images'] = len(data['init_images'])
    print("Got new request" + json.dumps(data_for_print), flush=True)
    if not prompt:
        return jsonify({"error": "missing prompt"}), 400

    init_images_b64 = data.get("init_images")
    if not isinstance(init_images_b64, list) or len(init_images_b64) == 0:
        return jsonify({"error": "missing init_images (base64 PNG/JPEG)"}), 400

    init_images: List[Image.Image] = []
    try:
        for idx, image_b64 in enumerate(init_images_b64):
            if not isinstance(image_b64, str) or not image_b64:
                return jsonify({"error": f"init_images[{idx}] is not a base64 string"}), 400
            init_images.append(base64_to_pil(image_b64))
    except Exception as e:
        return jsonify({"error": f"failed to decode init images: {e}"}), 400

    seed = int(data.get("seed", random.randint(1, 2 ** 32 - 1)))
    steps = int(data.get("steps", data.get("num_inference_steps", 50)))

    width = int(data.get("width", init_images[0].size[0]))
    height = int(data.get("height", init_images[0].size[1]))
    width, height = normalize_size(width, height, multiple=32)

    return infer_image_to_json(
        prompt=prompt,
        steps=steps,
        seed=seed,
        width=width,
        height=height,
        mode="img2img",
        init_images=init_images,
    )


if __name__ == "__main__":
    app.run(host="localhost", port=args.port)
