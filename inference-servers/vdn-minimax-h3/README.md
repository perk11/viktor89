# vdn-minimax-h3

HTTP inference server for [VDN-MiniMax-H3](https://github.com/OpenVDN/vdn-minimax-h3)
(Video DeltaNet on MiniMax H3): text → video **with stereo soundtrack**, 768×1344
canvas at 24 fps. Serves only the released **8-step** model (VDN-H3-8-step, the
Stage-DMD `stage-dmd-step-250` artifact) and only the single-GPU render path —
the equivalent of upstream's `scripts/inference/8nfe_tuned_fp8.sh` (inference
kernels + fp8), exposed as a Flask API modeled after `video-generic-comfy`.

The API is `POST /txt2vid`:

```json
{
  "prompt": "<MiniMax-H3-style structured prompt>",
  "seed": 12345,
  "num_frames": 345,
  "model": "vdn-minimax-h3-8-step"
}
```

- `prompt` — required. Should follow the MiniMax-H3 video prompt-writing guide
  (shot-by-shot description + `overall_soundscape` + `non_diegetic_music`, see
  `prompts/README.md` in the upstream repo). On the Viktor89 side use the
  `"preprocessor": "minimax-h3"` model config key to rewrite user prompts into
  this format before they reach the server.
- `model` — optional, must be `vdn-minimax-h3-8-step` (anything else is a 400).
- `seed` — optional, random when omitted.
- `num_frames` — optional (default 345), clamped to 120–345 (5–14.4 s) and
  snapped up to the `17n + 5` form the video VAE requires.
- `width` / `height` — optional (default 1344×768 landscape), snapped to the
  released checkpoint's envelope: multiples of 32, each side at least 512,
  area ≤ 768×1344, aspect ratio ≤ 4:1 — e.g. 1080×1920 becomes 768×1344
  portrait, 768×768 stays square. Smaller canvases are proportionally faster
  and lighter. The first request at a new (frames × canvas) shape pays kernel
  (re)compilation.

`steps`, `negative_prompt` and `cfg_scale` are accepted but ignored: the turbo
checkpoint is distilled to exactly 8 NFE and guidance is baked in. Response format
(same as the other video servers):

```json
{
  "videos": ["<base64 mp4: H.264 video + audio>"],
  "info": "{\"infotexts\": [\"<prompt>\\nSeed: ..., Frames: ..., Steps: 8, Model: vdn-minimax-h3-8-step\"]}"
}
```

## Requirements

- One NVIDIA GPU: the fp8 render stack sits around ~50 GB in steady state, so
  96 GB-class cards work; upstream's benchmarks (345-frame clips) used H200/B200,
  and denoising memory grows with the frame count — if a long clip OOMs, request
  fewer `num_frames`.
- Host RAM: model assembly runs on the CPU (upstream's in-GPU order — bf16 load,
  branch/LoRA merge, fp8 quantisation with the bf16 originals still retained —
  peaks above 100 GB of VRAM and OOMs on ~96 GB cards). The assembly peaks at
  ~130 GB of RAM, and with the default `--text_encoder_device cuda` the render
  stack (~35 GB fp8) and the text encoder (~62 GB bf16) are later both held in
  RAM during prompt encoding, so 192 GB+ is recommended. Disk: ~145 GB of weights.
  Assembly on CPU takes a few extra minutes; the retained bf16 originals inside
  the quantised Linears are dropped afterwards (upstream's `revert_fp8` is not
  supported by this server).
- Upstream's environment: Python 3.12, torch 2.13 cu129, FlashAttention 4,
  Triton, and their patched Diffusers (FlexAttention's Flash backend is
  required). A first-class compile step means the very first run of a given
  frame count takes several extra minutes.

## Setup

```bash
git clone https://github.com/OpenVDN/vdn-minimax-h3
cd vdn-minimax-h3
conda create -n vdn python=3.12 -y
conda activate vdn
pip install uv
uv pip install torch==2.13.0 --index-url https://download.pytorch.org/whl/cu129
uv pip install --prerelease=allow -e .
bash scripts/setup_diffusers.sh
uv pip install flask

# Weights: the base model + the 8-step artifact (~77 GB)
hf download OpenVDN/vdn-minimax-h3 --include "h3-base/*" "stage-dmd-step-250/*" --local-dir ckpts
```

The Qwen3-VL text encoder + processor (~62 GB) download automatically from
`MiniMaxAI/MiniMax-H3` on the first prompt encode (pass `--text_encoder_root`
to use a local snapshot instead). Set `HF_TOKEN` if the Hub repo is gated for
your account. Model weights are subject to the MiniMax H3 Community License
Agreement, not the repo's Apache-2.0.

## Baking (skip the per-startup assembly)

Every startup otherwise re-runs the CPU assembly (bf16 load, LoRA merge, fp8
quantisation of 363 Linears — about 3 minutes plus the GPU transfer). `bake.py`
assembles once and writes a lean directory (one ~35 GB fp8 safetensors + the
VAEs) that `main.py` loads directly via `--baked_dir`; the bf16 shards and the
stage artifacts are then no longer needed at all:

```bash
python bake.py --repo_dir /path/to/vdn-minimax-h3 \
    --out /path/to/vdn-minimax-h3/ckpts/baked-vdn-h3-8step

# optional, reclaims ~82 GB: removes h3-base/, stage-dmd-step-250/ and
# stage-b-step-2000/ from the weights root AFTER a successful verified bake
python bake.py --repo_dir ... --out ... --delete_source

python main.py --repo_dir /path/to/vdn-minimax-h3 \
    --baked_dir /path/to/vdn-minimax-h3/ckpts/baked-vdn-h3-8step --port 8211
```

`--source` (default `<repo_dir>/ckpts`), `--checkpoint` and `--base_source`
default to the standard `hf download` layout (`h3-base/`, `stage-dmd-step-250/`).
Unless `--skip_text_encoder` is passed, the bake also converts the MiniMax-H3
**text encoder** to the weight-only-fp8 layout (~34 GB instead of the 62 GB bf16
snapshot) into `text_encoder/` + copies `processor/`, and verifies it loads —
the server then reads only the baked directory and never touches the bf16
snapshot again (which can be deleted from the HF cache if nothing else uses
it, reclaiming ~62 GB). Pass `--text_encoder_root` to bake from a specific
snapshot (default: the cached Hub one).
The bake must run on the machine/GPU that serves the model: the fp8 scale
granularity (per-tensor vs rowwise) is chosen per architecture, and the loader
refuses a mismatched bake. Unless `--skip_warmup` is passed, the bake also
pre-compiles the kernels (2 discarded NFE at 345 frames, 1344x768, on the same
patched kernels the server runs) — Inductor/Triton caches are on-disk and
shared across processes, so the server's first request at that shape starts
warm; other shapes (frame counts, canvases, prompt lengths) still compile
lazily on first use into the same caches. Startup with `--baked_dir` drops to
a mmap safetensors load plus the GPU transfer (about a minute).

### sm_120 GPUs (RTX PRO / GeForce Blackwell)

Two architecture-specific adaptations, both automatic on capability ≥ 12:

- **fp8 scales are forced to rowwise.** Upstream picks per-tensor scales for every
  capability ≥ 10, but that fast `torch._scaled_mm` path is datacenter sm_100 only;
  on sm_120 per-tensor runs slowly while rowwise (the sm_90 path) is fast. The scale
  granularity is baked into the weights, so **bake on the sm_120 machine** — the
  loader refuses a granularity mismatch and asks for a re-bake.
- **The window softmax runs decomposed, re-routed to plain SDPA.** Upstream's
  `decomposed` backend expresses the window as a union of dense attention calls;
  its windows leg calls the FA4 CuteDSL varlen kernel, which faults on sm_120 —
  `patch_decomposed_for_sm120()` (`vdn_model.py`) re-implements that one call with
  torch SDPA (flash-2/cuDNN kernels, which work fine there), reusing upstream's
  plan. `force_dynamic_flex()` keeps the failure latch's flex fallback on its
  dynamic Triton variant (static CuteDSL faults). Every other inference kernel
  (fused QKV+RoPE, the linear branch, fused block pointwise) stays on.
  A `--profile` run shows where the NFE seconds go on any given GPU; on a 300 W
  part expect roughly half the time in the window softmax and a third in the fp8
  GEMMs (~535 TFLOPS effective), i.e. near the card's practical ceiling — not much
  headroom left above ~25–28 s/NFE at 345 frames.

If anything still faults, `--softmax_backend ref` runs the eager reference softmax
(much slower), and `--no_inference_kernels` drops to the fully eager path.

Between denoising and the VAE decode the server frees the denoise workspace
(`torch.cuda.empty_cache()`); on 96 GB cards the decode still runs close to
the limit at 345 frames — shorter clips reduce every stage's footprint.

## Running

From this directory:

```bash
python main.py --port 8211 --repo_dir /path/to/vdn-minimax-h3
```

| Option | Default | Notes |
|---|---|---|
| `--repo_dir` | — | vdn-minimax-h3 checkout; relative `--checkpoint` resolves against it |
| `--checkpoint` | `ckpts/stage-dmd-step-250` | the 8-step artifact (VDN-H3-8-step) |
| `--baked_dir` | off | load a `bake.py` directory instead of assembling (see above) |
| `--config` | `<repo_dir>/configs/inference/8nfe_tuned_fp8.yaml` | upstream inference overlay; anything not 8 steps is refused |
| `--device` | `cuda:0` | single GPU only |
| `--text_encoder_device` | `cuda` | how prompts are encoded: `cuda` quantises the encoder to weight-only fp8 (~31 GB, the ComfyUI layout, per-channel scales, bf16 compute — pass `--no_text_encoder_fp8` for exact bf16 embeddings) and swaps just the encoder in through **pinned** host buffers per new prompt — the render stack never moves; `cpu` encodes in bf16 host RAM, no swapping; `split` loads the encoder **once**, layer-split across GPU + host RAM (accelerate `device_map`) — no swapping at all, at the cost of slower encodes if the CPU is weak |
| `--warmup_steps` | `0` (off) | discarded startup NFE that pre-compile the kernels for the default frame count; the first real request pays the compilation instead |
| `--text_encoder_root` | Hub download | a local MiniMax-H3 snapshot with `processor/` and `text_encoder/` (HF-cache snapshot path or `hf download MiniMaxAI/MiniMax-H3 --local-dir` tree); the OpenVDN `ckpts` tree does **not** contain the text encoder |
| `--prompt_cache_dir` | `prompt-cache/` next to `main.py` | encoded prompts, keyed by prompt hash, capped at 500 MiB (least-recently-used eviction) |

Generation is serialized by a semaphore (one GPU). Startup loads the render
stack, then warms up the kernels with a bundled example prompt; the first
request with a frame count other than the warmed-up 345 pays kernel
(re)compilation for that shape.

## Viktor89 integration

Add to `config.json` (`videoModels`):

```json
"vdn-minimax-h3-8-step": {
    "url": "http://localhost:8211",
    "model": "vdn-minimax-h3-8-step",
    "preprocessor": "minimax-h3"
}
```

`preprocessor` makes the bot rewrite user prompts with the MiniMax-H3 prompt
guide (VDN-H3 is conditioned by the same H3 text latents), and is also sent in
the request body, where the server ignores it.
