"""Inference server for VDN-MiniMax-H3 (VDN-H3), the hybrid-attention MiniMax H3
video model. Single GPU, the released 8-step (Stage-DMD turbo) checkpoint only.

Renders through the upstream src/inference pipeline, mirroring
scripts/inference/8nfe_tuned_fp8.sh: inference kernels + fp8, 768x1344 canvas at
24 fps, video with a stereo soundtrack, 8 NFE. Prompts are encoded on the fly
with the MiniMax-H3 Qwen3-VL text encoder and cached on disk.

API modeled after video-generic-comfy: POST /txt2vid.
"""
import argparse
import base64
import hashlib
import itertools
import json
import os
import random
import sys
import tempfile
import threading
import time
import traceback
from pathlib import Path

from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="Inference server for VDN-MiniMax-H3 (8-step, single GPU).")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--repo_dir', type=str,
                    help='Path to the vdn-minimax-h3 checkout; relative checkpoint paths resolve against it',
                    required=True)
parser.add_argument('--checkpoint', type=str, default='ckpts/stage-dmd-step-250',
                    help='VDN-H3-8-step artifact (Stage-DMD step 250); absolute or --repo_dir-relative '
                         '(unused with --baked_dir)')
parser.add_argument('--config', type=str, default=None,
                    help='Inference overlay YAML; default <repo_dir>/configs/inference/8nfe_tuned_fp8.yaml')
parser.add_argument('--base_source', type=str, default=None,
                    help='Base weights root override (default: the checkpoint spec, ckpts/h3-base)')
parser.add_argument('--vae_source', type=str, default=None, help='VAE weights root override')
parser.add_argument('--baked_dir', type=str, default=None,
                    help='A directory produced by bake.py: loads the pre-assembled fp8 stack '
                         'directly (no CPU assembly, no bf16 checkpoint needed)')
parser.add_argument('--device', type=str, default='cuda:0')
parser.add_argument('--text_encoder_device', choices=['cuda', 'cpu', 'split'], default='cuda',
                    help='cuda (default): the text encoder is quantised to weight-only fp8 '
                         '(about 31 GB, the ComfyUI layout) and swapped in through pinned host '
                         'buffers per new prompt -- the render stack never moves; cpu: keep the '
                         'encoder in host RAM (bf16) and encode there, no swapping; split: load '
                         'the encoder ONCE, layer-split across GPU and host RAM (accelerate '
                         'device_map, budget from free VRAM) -- no swapping, but the '
                         'CPU-resident layers make each new prompt slower on weak CPUs')
parser.add_argument('--text_encoder_root', type=str, default=None,
                    help='A MiniMax-H3 snapshot with processor/ and text_encoder/; '
                         'default: downloaded from the Hub on first use')
parser.add_argument('--prompt_cache_dir', type=str, default=None,
                    help='Where encoded prompts are cached (default: prompt-cache/ next to this file)')
parser.add_argument('--warmup_steps', type=int, default=0,
                    help='Startup NFE that pre-compile the kernels for the default frame '
                         'count, then discarded (default: off; the first real request '
                         'pays the compilation instead)')
parser.add_argument('--no_text_encoder_fp8', action='store_true',
                    help='Keep the text encoder in bf16 (exact upstream embeddings) instead of '
                         'weight-only fp8. In cuda mode that means swapping the transformer out '
                         'for each new prompt again (much slower); fp8 is the ComfyUI layout')
parser.add_argument('--profile', action='store_true',
                    help='Run one instrumented NFE at startup and print the top CUDA '
                         'kernels by self time, then continue serving')
parser.add_argument('--softmax_backend', choices=['auto', 'flex', 'decomposed', 'ref'], default=None,
                    help='Override kernels.softmax_backend (default: the YAML). "ref" is the eager '
                         'window softmax -- much slower, but it avoids the FlashAttention-based '
                         'kernels that may not run on every GPU architecture')
parser.add_argument('--no_inference_kernels', action='store_true',
                    help='Run the transformer blocks on the eager reference path '
                         '(kernels.inference_kernels=false) instead of the fused/compiled kernels')
args = parser.parse_args()

repo_dir = Path(args.repo_dir).resolve()
if not (repo_dir / 'src' / 'inference').is_dir():
    raise SystemExit(f"{repo_dir} is not a vdn-minimax-h3 checkout (no src/inference)")
sys.path.insert(0, str(repo_dir))

import torch
from diffusers.modular_pipelines.minimax_h3.modular_pipeline import align_num_frames
from omegaconf import OmegaConf

import vdn_model
from vdn_model import quantize_linears_to_weight_only_fp8
import src.inference.render as render_module
from src.config.inference import InferenceConfig, validate_ablation, validate_kernels
from src.inference.encode_prompt import encode
from src.inference.render import decode_and_save, generate_latents, load_text
from src.paths import upstream_snapshot

SUPPORTED_MODEL = 'vdn-minimax-h3-8-step'
FPS = 24
MIN_FRAMES = 120  # 5 s, MiniMax-H3's shortest clip
MAX_FRAMES = 345  # 14.4 s, the released 8-step workload
DEFAULT_WIDTH, DEFAULT_HEIGHT = 1344, 768
PROMPT_CACHE_MAX_BYTES = 500 * 1024 * 1024
CANVAS_MULTIPLE = 32    # video VAE compression (16) x patch (2)
CANVAS_MAX_AREA = 768 * 1344
CANVAS_MAX_RATIO = 4


def canvas_for(width, height):
    """Snap a requested canvas onto the released checkpoint's envelope: multiples of
    32, area <= 768x1344, aspect ratio <= 4:1 (diffusers' resolve_canvas_size rules)."""
    width = max(512, round(width / CANVAS_MULTIPLE) * CANVAS_MULTIPLE)
    height = max(512, round(height / CANVAS_MULTIPLE) * CANVAS_MULTIPLE)
    if width > height * CANVAS_MAX_RATIO:
        width = height * CANVAS_MAX_RATIO
    elif height > width * CANVAS_MAX_RATIO:
        height = width * CANVAS_MAX_RATIO
    if width * height > CANVAS_MAX_AREA:
        shrink = (CANVAS_MAX_AREA / (width * height)) ** 0.5
        width = max(512, round(width * shrink / CANVAS_MULTIPLE) * CANVAS_MULTIPLE)
        height = max(512, round(height * shrink / CANVAS_MULTIPLE) * CANVAS_MULTIPLE)
        while width * height > CANVAS_MAX_AREA:
            if width >= height:
                width -= CANVAS_MULTIPLE
            else:
                height -= CANVAS_MULTIPLE
    return width, height


def build_config():
    config_path = Path(args.config) if args.config else repo_dir / 'configs' / 'inference' / '8nfe_tuned_fp8.yaml'
    overrides = [
        f'checkpoint={args.checkpoint}',
        f'render.device={args.device}',
        'render.num_steps=8',
        'render.warmup_steps=0',  # warm-up runs once at startup, not per request
        'render.prompt_file=(server)',
        'render.out=(server)',
    ]
    if args.base_source:
        overrides.append(f'base_source={args.base_source}')
    if args.vae_source:
        overrides.append(f'vae_source={args.vae_source}')
    if args.softmax_backend:
        overrides.append(f'kernels.softmax_backend={args.softmax_backend}')
    if args.no_inference_kernels:
        overrides.append('kernels.inference_kernels=false')
    # sm_120x (RTX/workstation Blackwell): the CuteDSL kernels (static flex, and the
    # windows leg of decomposed) fault there. Pin decomposed -- patch_decomposed_for_sm120()
    # re-runs its windows on plain SDPA -- and force_dynamic_flex() keeps the latch's
    # flex fallback on the dynamic Triton variant.
    if (args.softmax_backend is None and torch.cuda.is_available()
            and torch.cuda.get_device_capability(args.device)[0] >= 12):
        print('sm_120+ GPU detected: pinning the window softmax to decomposed (its CuteDSL '
              'windows are re-routed to plain SDPA on this GPU).', flush=True)
        overrides.append('kernels.softmax_backend=decomposed')

    cfg = OmegaConf.merge(
        OmegaConf.structured(InferenceConfig),
        OmegaConf.load(config_path),
        OmegaConf.from_dotlist(overrides),
    )
    validate_ablation(cfg)
    validate_kernels(cfg)
    if cfg.render.num_steps != 8:
        raise SystemExit(f'{config_path} renders {cfg.render.num_steps} steps; '
                         f'this server only serves the 8-step model')
    return cfg


class PinnedTransformerSwap:
    """Moves a module between GPU and pre-allocated pinned host buffers. Pinned DMA
    runs several times faster per GB than the pageable copies a plain .to() makes,
    and the buffers are allocated once (on the first move, from wherever the tensors
    are) and reused. Works for either call order: to_cpu() first (GPU-resident
    module) or to_gpu() first (CPU-resident module, e.g. the freshly loaded fp8
    encoder)."""

    def __init__(self, module):
        self.module = module
        self._pinned = {}

    def _entries(self):
        for module in self.module.modules():
            for attr, tensor in itertools.chain(module._parameters.items(),
                                                module._buffers.items()):
                if tensor is not None:
                    yield module, attr, tensor

    def _assign(self, module, attr, tensor):
        if attr in module._parameters:
            module._parameters[attr].data = tensor
        else:
            module._buffers[attr] = tensor

    def _pin(self, module, attr, tensor):
        key = (id(module), attr)
        pinned = self._pinned.get(key)
        if pinned is None:
            if tensor.is_cuda:
                pinned = torch.empty_like(tensor, device='cpu', pin_memory=True)
                pinned.copy_(tensor, non_blocking=True)
            elif tensor.is_pinned():
                pinned = tensor
            else:
                # straight into pinned memory: clone().pin_memory() would copy twice
                pinned = torch.empty_like(tensor, device='cpu', pin_memory=True)
                pinned.copy_(tensor)
            self._pinned[key] = pinned
            self._assign(module, attr, pinned)
        return pinned

    def pin(self):
        """Page-lock everything WITHOUT touching the GPU: safe to run in the
        background while a generation owns the device."""
        for module, attr, tensor in self._entries():
            self._pin(module, attr, tensor)

    def to_cpu(self):
        for module, attr, tensor in self._entries():
            pinned = self._pin(module, attr, tensor)
            if tensor is not pinned and tensor.is_cuda:
                pinned.copy_(tensor, non_blocking=True)
                self._assign(module, attr, pinned)
        torch.cuda.synchronize()
        torch.cuda.empty_cache()

    def to_gpu(self, device):
        for module, attr, tensor in self._entries():
            pinned = self._pin(module, attr, tensor)
            self._assign(module, attr, pinned.to(device, non_blocking=True))
        torch.cuda.synchronize()


class VdnH3Renderer:
    """Owns the single-GPU render stack and the on-demand prompt encoder.

    The Qwen3-VL text encoder (~62 GB bf16) and the render stack (fp8 transformer +
    VAEs) do not fit in one GPU's memory together, so with --text_encoder_device cuda
    the render stack is swapped to host RAM while a prompt is encoded, then swapped
    back. The encoder stays resident in host RAM either way; only its weights move to
    the GPU for the encode. Encoded prompts are cached on disk, so the swap cost is
    only paid for prompts not seen before.
    """

    def __init__(self, cfg):
        self.cfg = cfg
        self.device = cfg.render.device
        self.sem = threading.Semaphore()
        self.cache_dir = Path(args.prompt_cache_dir) if args.prompt_cache_dir else Path(__file__).with_name('prompt-cache')
        self.cache_dir.mkdir(parents=True, exist_ok=True)
        self._processor = None
        self._text_encoder = None
        self._text_encoder_fp8 = False
        self._transformer_swap = None
        self._encoder_swap = None
        self._text_encoder_lock = threading.Lock()
        self._encoder_ready = threading.Event()
        self._encoder_ready.set()

        started = time.time()
        if args.baked_dir:
            self.model = vdn_model.load_baked_stack(
                args.baked_dir, self.device,
                inference_kernels=bool(cfg.kernels.inference_kernels),
                softmax_backend=str(cfg.kernels.softmax_backend))
        else:
            print('Loading VDN-H3 render stack (CPU assembly, fp8, then GPU)...', flush=True)
            self.model = vdn_model.assemble_render_stack(cfg)
            vdn_model.move_to_device(self.model, self.device)
        print(f'Render stack ready in {time.time() - started:.0f}s', flush=True)

    def _ensure_text_encoder(self):
        with self._text_encoder_lock:
            self._ensure_text_encoder_locked()

    def start_background_encoder_preload(self):
        """Load + pin the fp8 text encoder right after startup so the first new
        prompt does not pay the one-time ~30 s pinning. Pinning is host-side only
        (cudaHostAlloc + memcpy), so it deliberately does NOT take the generation
        semaphore -- taking it here deadlocks against a request that already holds
        the lock and waits on _encoder_ready."""
        if args.text_encoder_device != 'cuda' or args.no_text_encoder_fp8:
            return
        self._encoder_ready.clear()

        def worker():
            try:
                self._ensure_text_encoder()
                if self._text_encoder_fp8 and self._encoder_swap is None:
                    print('Pre-pinning the fp8 text encoder in host RAM...', flush=True)
                    started = time.time()
                    swap = PinnedTransformerSwap(self._text_encoder)
                    swap.pin()
                    self._encoder_swap = swap
                    print(f'fp8 text encoder pinned and ready in {time.time() - started:.0f}s',
                          flush=True)
            except Exception as exc:
                print(f'Background text-encoder preload failed ({exc}); '
                      f'the first request will load it synchronously', flush=True)
            finally:
                self._encoder_ready.set()

        threading.Thread(target=worker, daemon=True, name='text-encoder-preload').start()

    def _ensure_text_encoder_locked(self):
        if self._text_encoder is not None:
            return
        from transformers import Qwen3VLForConditionalGeneration, Qwen3VLProcessor

        # resolution order: an explicit --text_encoder_root, then a baked fp8 encoder
        # inside --baked_dir (no bf16 read, no runtime quantisation), then the Hub
        # snapshot (cached after first use)
        baked_encoder_dir = Path(args.baked_dir) if args.baked_dir else None
        if args.text_encoder_root:
            root = args.text_encoder_root
            missing = [name for name in ('processor', 'text_encoder')
                       if not (Path(root) / name).is_dir()]
            if missing:
                raise SystemExit(
                    f'--text_encoder_root {root} has no {"/".join(missing)} subfolder(s); it must '
                    f'point at a MiniMax-H3 snapshot holding processor/ and text_encoder/ (the '
                    f'OpenVDN ckpts tree does NOT contain the text encoder)')
        elif (baked_encoder_dir is not None
              and (baked_encoder_dir / 'text_encoder' / vdn_model.BAKED_WEIGHTS_FILE).exists()
              and args.text_encoder_device == 'cuda' and not args.no_text_encoder_fp8):
            print('Loading the baked fp8 text encoder...', flush=True)
            started = time.time()
            # load the processor from the copied directory itself: transformers'
            # local-dir resolution ignores subfolder= for sub-components (it looks for
            # preprocessor_config.json at the given root only)
            try:
                self._processor = Qwen3VLProcessor.from_pretrained(str(baked_encoder_dir / 'processor'))
            except Exception as exc:
                processor_dir = baked_encoder_dir / 'processor'
                if processor_dir.is_dir():
                    entries = []
                    for p in sorted(processor_dir.glob('*')):
                        if p.is_symlink():
                            entries.append(f'{p.name} -> {os.readlink(p)}'
                                           + ('' if p.is_file() else ' (DANGLING)'))
                        else:
                            entries.append(p.name)
                    listing = ', '.join(entries) or '(empty)'
                else:
                    listing = '(directory missing)'
                print(f'Baked processor copy unusable ({exc}); it contains: {listing}. '
                      f'Falling back to the Hub snapshot processor.', flush=True)
                self._processor = Qwen3VLProcessor.from_pretrained(
                    str(Path(upstream_snapshot('processor')) / 'processor'))
            self._text_encoder = vdn_model.load_baked_text_encoder(baked_encoder_dir, 'cpu')
            self._text_encoder_fp8 = True
            print(f'Text encoder ready in {time.time() - started:.0f}s (baked fp8)', flush=True)
            return
        else:
            root = upstream_snapshot('processor', 'text_encoder')
        print(f'Text encoder root: {root}', flush=True)
        print('Loading the Qwen3-VL text encoder (about 62 GB)...', flush=True)
        started = time.time()
        self._processor = Qwen3VLProcessor.from_pretrained(str(Path(root) / 'processor'))
        if args.text_encoder_device == 'split':
            free_gib = torch.cuda.mem_get_info(self.device)[0] / 2**30
            budget = int(free_gib - 4)
            if budget < 8:
                raise SystemExit(f'only {free_gib:.0f} GiB free on {self.device}; not enough for '
                                 f'--text_encoder_device split (use cuda or cpu)')
            print(f'split: up to {budget} GiB of encoder layers on {self.device}, rest in host RAM',
                  flush=True)
            self._text_encoder = Qwen3VLForConditionalGeneration.from_pretrained(
                root, subfolder='text_encoder', dtype=torch.bfloat16,
                device_map='auto', max_memory={0: f'{budget} GiB', 'cpu': '160 GiB'})
        else:
            self._text_encoder = Qwen3VLForConditionalGeneration.from_pretrained(
                root, subfolder='text_encoder', dtype=torch.bfloat16)
            if args.text_encoder_device == 'cuda' and not args.no_text_encoder_fp8:
                replaced = quantize_linears_to_weight_only_fp8(self._text_encoder)
                self._text_encoder_fp8 = True
                print(f'Text encoder quantised to weight-only fp8: {replaced} Linears '
                      f'(about 31 GB instead of 62 -- fits beside the render stack, '
                      f'the transformer never has to move)', flush=True)
        self._text_encoder.eval().requires_grad_(False)
        print(f'Text encoder ready in {time.time() - started:.0f}s', flush=True)

    def _set_render_stack_device(self, target):
        if target == 'cpu':
            if self._transformer_swap is None:
                self._transformer_swap = PinnedTransformerSwap(self.model.transformer)
            self._transformer_swap.to_cpu()
        else:
            self._transformer_swap.to_gpu(self.device)

    def _enforce_prompt_cache_cap(self):
        """Keep the prompt cache under PROMPT_CACHE_MAX_BYTES, evicting the least
        recently used entries first (hits refresh mtime)."""
        files = list(self.cache_dir.glob('*.pt'))
        total = sum(path.stat().st_size for path in files)
        if total <= PROMPT_CACHE_MAX_BYTES:
            return
        removed = 0
        for path in sorted(files, key=lambda p: p.stat().st_mtime):   # oldest first
            if total <= PROMPT_CACHE_MAX_BYTES:
                break
            total -= path.stat().st_size
            path.unlink()
            removed += 1
        print(f'Prompt cache over {PROMPT_CACHE_MAX_BYTES // 2**20} MiB: removed {removed} '
              f'oldest prompt(s)', flush=True)

    def encode_prompt(self, prompt: str) -> Path:
        cache_file = self.cache_dir / (hashlib.sha256(prompt.encode()).hexdigest() + '.pt')
        if cache_file.exists():
            print(f'Prompt cache hit: {cache_file.name}', flush=True)
            cache_file.touch()   # refresh for least-recently-used eviction
            return cache_file
        self._enforce_prompt_cache_cap()

        self._ensure_text_encoder()
        if args.text_encoder_device == 'cpu':
            encode(self._processor, self._text_encoder, prompt, str(cache_file), 'cpu')
            return cache_file
        if args.text_encoder_device == 'split':
            # the encoder is resident (layer-split GPU/CPU); inputs go to its first shard
            first_device = next(self._text_encoder.parameters()).device
            encode(self._processor, self._text_encoder, prompt, str(cache_file), first_device)
            return cache_file

        # 'cuda' + fp8 encoder: the transformer stays resident; only the compact
        # encoder moves, through pinned buffers. 'cuda' + bf16 encoder: the 62 GB
        # encoder cannot coexist with the transformer, so the transformer is the one
        # that swaps (the slow legacy path).
        self._encoder_ready.wait()
        if getattr(self, '_text_encoder_fp8', False):
            started = time.time()
            swap_in = time.time()
            if self._encoder_swap is None:
                self._encoder_swap = PinnedTransformerSwap(self._text_encoder)
            self._encoder_swap.to_gpu(self.device)
            swap_in = time.time() - swap_in
            try:
                encode_started = time.time()
                encode(self._processor, self._text_encoder, prompt, str(cache_file), self.device)
                encode_seconds = time.time() - encode_started
            finally:
                swap_out = time.time()
                self._encoder_swap.to_cpu()
                swap_out = time.time() - swap_out
            print(f'Prompt encoded in {time.time() - started:.1f}s '
                  f'(swap in {swap_in:.1f}s, encode {encode_seconds:.1f}s, swap out {swap_out:.1f}s)',
                  flush=True)
            return cache_file

        encode_device = self.device
        swapped = False
        try:
            print('Offloading the transformer to pinned host buffers for prompt encoding', flush=True)
            swapped = True
            self._set_render_stack_device('cpu')
            print('Moving the text encoder to the GPU...', flush=True)
            self._text_encoder.to(self.device)
            started = time.time()
            encode(self._processor, self._text_encoder, prompt, str(cache_file), encode_device)
            print(f'Prompt encoded in {time.time() - started:.1f}s', flush=True)
        finally:
            if swapped:
                self._text_encoder.to('cpu')
                torch.cuda.empty_cache()
                print('Moving the transformer back to the GPU', flush=True)
                self._set_render_stack_device(self.device)
        return cache_file

    def generate_mp4(self, prompt: str, seed: int, num_frames: int,
                     width: int = DEFAULT_WIDTH, height: int = DEFAULT_HEIGHT) -> bytes:
        prompt_embeds, text_token_tags = load_text(str(self.encode_prompt(prompt)), self.device)

        print(f'Denoising {num_frames} frames ({num_frames / FPS:.2f}s) at {width}x{height}, '
              f'8 NFE, seed {seed}', flush=True)
        step_seconds = []
        # generate_latents reads the canvas from module-level LATENT_H/LATENT_W (in
        # latent units, /16); swap them around the call so upstream's code runs unmodified
        previous_canvas = render_module.LATENT_H, render_module.LATENT_W
        render_module.LATENT_H, render_module.LATENT_W = height // 16, width // 16
        try:
            latents, audio_latents = generate_latents(
                self.model.transformer,
                prompt_embeds,
                text_token_tags,
                num_frames,
                self.cfg.render.num_steps,
                seed,
                self.device,
                video_shift=self.cfg.render.video_shift,
                audio_shift=self.cfg.render.audio_shift,
                step_seconds=step_seconds,
            )
        finally:
            render_module.LATENT_H, render_module.LATENT_W = previous_canvas
        denoise = sum(step_seconds)
        print(f'timing: denoise {denoise:.2f}s ({denoise / len(step_seconds):.2f}s/NFE)', flush=True)

        # release the denoise workspace before the VAE decode allocates: without this
        # the decode starts with tens of GB of cached activation blocks still reserved
        torch.cuda.empty_cache()

        with tempfile.TemporaryDirectory() as tmp_dir:
            out_path = str(Path(tmp_dir) / 'video.mp4')
            decode_and_save(latents, audio_latents, self.model.vae, self.model.audio_vae, out_path, self.device)
            torch.cuda.empty_cache()
            with open(out_path, 'rb') as f:
                return f.read()

    def _example_embeds(self):
        example = repo_dir / 'prompts' / 'example_2.pt'
        if example.exists():
            return load_text(str(example), self.device)
        # any embedding of the right width works: these runs only exercise kernels
        return (torch.randn(16, 5120, device=self.device, dtype=torch.bfloat16),
                torch.ones(16, dtype=torch.long))

    def warm_up(self):
        if args.warmup_steps <= 0:
            return
        prompt_embeds, text_token_tags = self._example_embeds()
        num_frames = int(align_num_frames(int(self.cfg.render.num_frames), 17, 5))
        print(f'Warm-up: {args.warmup_steps} NFE at {num_frames} frames (compiles kernels, discarded)', flush=True)
        generate_latents(
            self.model.transformer, prompt_embeds, text_token_tags, num_frames,
            args.warmup_steps, 42, self.device,
            video_shift=self.cfg.render.video_shift, audio_shift=self.cfg.render.audio_shift,
        )

    def profile_one_nfe(self):
        """One instrumented NFE at the default frame count, top kernels by self CUDA
        time -- the answer to 'where do the seconds go' when a GPU needs hand-tuning."""
        from torch.profiler import ProfilerActivity, profile

        prompt_embeds, text_token_tags = self._example_embeds()
        num_frames = int(align_num_frames(int(self.cfg.render.num_frames), 17, 5))
        print(f'Profiling 1 NFE at {num_frames} frames...', flush=True)
        generate_latents(  # compile first so the profiled pass is steady-state
            self.model.transformer, prompt_embeds, text_token_tags, num_frames,
            1, 42, self.device,
            video_shift=self.cfg.render.video_shift, audio_shift=self.cfg.render.audio_shift)
        torch.cuda.synchronize()
        with profile(activities=[ProfilerActivity.CPU, ProfilerActivity.CUDA]) as prof:
            generate_latents(
                self.model.transformer, prompt_embeds, text_token_tags, num_frames,
                1, 42, self.device,
                video_shift=self.cfg.render.video_shift, audio_shift=self.cfg.render.audio_shift)
            torch.cuda.synchronize()
        print(prof.key_averages().table(sort_by='self_cuda_time_total', row_limit=30), flush=True)


app = Flask(__name__)
cfg = build_config()
torch.set_grad_enabled(False)
if torch.cuda.is_available():
    props = torch.cuda.get_device_properties(cfg.render.device)
    print(f'GPU: {props.name} (sm_{props.major}{props.minor}, {props.total_memory / 2**30:.0f} GiB)', flush=True)
    if props.major >= 12:
        vdn_model.force_rowwise_fp8()
        print('fp8 scales forced to rowwise: the per-tensor _scaled_mm path is slow on sm_120', flush=True)
renderer = VdnH3Renderer(cfg)
renderer.start_background_encoder_preload()
if torch.cuda.is_available() and torch.cuda.get_device_capability(cfg.render.device)[0] >= 12:
    vdn_model.apply_sm120_adjustments(inference_kernels=bool(cfg.kernels.inference_kernels))
try:
    renderer.warm_up()
except Exception:
    traceback.print_exc()
    raise SystemExit(
        'Warm-up failed. An async "illegal memory access" usually means one of the '
        'window-softmax / fused kernels does not run on this GPU: retry with '
        '--softmax_backend ref (eager, slow) and/or --no_inference_kernels to bisect, '
        'and run with CUDA_LAUNCH_BLOCKING=1 to identify the faulting kernel.')
if args.profile:
    renderer.profile_one_nfe()


@app.route('/txt2vid', methods=['POST'])
def generate_video():
    data = request.json
    print('Got new request', flush=True)
    print(data, flush=True)

    prompt = data.get('prompt')
    if not prompt:
        return jsonify({'error': 'Prompt is required.'}), 400
    model = data.get('model', SUPPORTED_MODEL)
    if model != SUPPORTED_MODEL:
        return jsonify({'error': f'Unknown model: {model}. This server only serves {SUPPORTED_MODEL}.'}), 400
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    num_frames = int(data.get('num_frames', renderer.cfg.render.num_frames))
    num_frames = int(align_num_frames(max(MIN_FRAMES, min(num_frames, MAX_FRAMES)), 17, 5))
    width, height = canvas_for(int(data.get('width', DEFAULT_WIDTH)),
                               int(data.get('height', DEFAULT_HEIGHT)))

    ignored = {key: data[key] for key in ('steps', 'negative_prompt', 'cfg_scale', 'preprocessor')
               if key in data}
    if ignored:
        print(f'Ignoring unsupported parameters (8 fixed steps): {ignored}', flush=True)

    infotext = (f'{prompt}\nSeed: {seed}, Frames: {num_frames} ({num_frames / FPS:.2f}s at 24 fps, '
                f'{width}x{height}), Steps: 8, Model: {SUPPORTED_MODEL}')

    renderer.sem.acquire()
    print('Acquired generation lock', flush=True)
    try:
        video_data = renderer.generate_mp4(prompt, seed, num_frames, width, height)
        video_contents_base64 = base64.b64encode(video_data).decode('utf-8')
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500
    finally:
        renderer.sem.release()

    return jsonify({
        'videos': [video_contents_base64],
        'info': json.dumps({
            'infotexts': [infotext]
        })
    })


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
