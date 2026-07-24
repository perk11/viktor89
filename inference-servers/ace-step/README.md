# ace-step

HTTP wrapper that exposes [ACE-Step 1.5 XL](https://github.com/ace-step/ACE-Step-1.5) as a `/sing`
model for the bot.

ACE-Step 1.5 ships its own asynchronous REST API server (`acestep-api`). This wrapper
adapts that async API to the synchronous `{voice_data, info}` contract the bot's
`SingApiClient` expects (`POST /txt_tags2music`), so no PHP changes are needed: just add an
entry to `singModels` in `config.json`.

Audio is requested from ACE-Step as `wav` (its opus output path is unreliable — it depends
on torchcodec + FFmpeg libs that frequently mismatch), then re-encoded to OGG/Opus in this
wrapper via the **`ffmpeg` CLI**, which is the format Telegram requires for voice notes. So
`ffmpeg` must be installed on the host.

## Installation

This wrapper is a thin HTTP adapter — the actual model runs in the official
[ACE-Step 1.5](https://github.com/ace-step/ACE-Step-1.5) API server. Both run from one
conda env. We use pip instead of `uv` (uv is upstream's convenience tool, not required).

Requirements: **Python 3.11 exactly** (ACE-Step's `requires-python` is pinned to `==3.11.*`),
the **`ffmpeg` CLI** on PATH (the wrapper re-encodes the output to OGG/Opus for Telegram),
and a CUDA GPU (MPS/ROCm/Intel/CPU also work upstream). XL models need **≥12GB VRAM** (with
offload) or **≥20GB** recommended.

Why conda: it pins the exact Python version ACE-Step needs (a plain `venv` just inherits your
system Python), and it matches this repo's other inference servers (e.g. `rmbg`). PyTorch pip
wheels ship their own CUDA runtime, so you do **not** need conda's `cudatoolkit`.

### 1. Set up the env and install ACE-Step + this wrapper

```bash
# conda env with the exact Python ACE-Step requires
conda create -n acestep -y python=3.11
conda activate acestep

# clone ACE-Step
git clone https://github.com/ACE-Step/ACE-Step-1.5.git
cd ACE-Step-1.5

# ACE-Step's pyproject.toml is written for uv: it pins torch to the CUDA 12.8 index and
# vendors a local nano-vllm. pip ignores that uv-specific config, so install those two
# explicitly, then the rest of the package from the cu128 torch index:
pip install ./acestep/third_parts/nano-vllm
pip install -e . --extra-index-url https://download.pytorch.org/whl/cu128

# flask is the only extra this wrapper adds
pip install flask

# the wrapper re-encodes ACE-Step's output to OGG/Opus for Telegram, so install the ffmpeg
# CLI. Either via the OS package manager:
#   sudo apt-get install -y ffmpeg
# or into the conda env:
#   conda install -c conda-forge ffmpeg

# download the XL SFT DiT (the default).
acestep-download --model acestep-v15-xl-sft
# The LM is OPTIONAL — only needed if you enable --thinking on the wrapper. Pick by VRAM:
# 0.6B (<12GB), 1.7B (12–16GB), 4B (≥24GB).
# acestep-download --model acestep-5Hz-lm-1.7B
```

(Already on Python 3.11 system-wide? You can use `python -m venv .venv && source .venv/bin/activate`
instead of conda.)

### 2. Run the two processes

Both processes use the `acestep` conda env. Run them in separate terminals:

```bash
# Terminal 1 — the ACE-Step async API server (runs the model), listens on :8001
ACESTEP_CONFIG_PATH=acestep-v15-xl-sft acestep-api
# Only if you enable --thinking on the wrapper, also load an LM on the server:
#   ACESTEP_INIT_LLM=true ACESTEP_LM_MODEL_PATH=acestep-5Hz-lm-1.7B \
#   ACESTEP_CONFIG_PATH=acestep-v15-xl-sft acestep-api

# Terminal 2 — this wrapper, listens on :8213
cd /path/to/viktor89/inference-servers/ace-step
python main.py --port 8213

# Sanity check both are up:
curl http://localhost:8001/health
curl http://localhost:8001/v1/models   # should list acestep-v15-xl-sft
```

Notes:
- On low-VRAM GPUs, add `ACESTEP_OFFLOAD_TO_CPU=true` (and optionally
  `ACESTEP_OFFLOAD_DIT_TO_CPU=true`) to the `acestep-api` launch command.
- To run a different XL DiT, set `ACESTEP_CONFIG_PATH`, download it with
  `acestep-download --model <name>`, and point this wrapper at it via `--dit_model`. Choices:
  - `acestep-v15-xl-sft` — default, highest text2music quality (50 steps + CFG)
  - `acestep-v15-xl-turbo` — fast (8 steps, no CFG); pair with `--inference_steps 8`
  - `acestep-v15-xl-base` — all tasks (text2music/cover/repaint/extract/lego/complete)

The wrapper defaults to the XL SFT DiT (highest text2music quality, 50 steps + CFG) in
**DiT-only mode** (no LM — this matches the API server's own default and avoids a broken LM
producing noise). See the options below to change them. The `--acestep_api_url` only needs
changing if you run the ACE-Step API on a non-default host/port.

Common options:

| Flag | Default | Description |
| --- | --- | --- |
| `--port` | (required) | Port this wrapper listens on |
| `--acestep_api_url` | `http://localhost:8001` | Official ACE-Step API server URL |
| `--dit_model` | `acestep-v15-xl-sft` | DiT model (must be loaded on the API server). Also: `acestep-v15-xl-turbo`, `acestep-v15-xl-base` |
| `--inference_steps` | `50` | Diffusion steps (50 for sft/base; pass `8` if you switch to turbo) |
| `--guidance_scale` | `7.0` | CFG strength (effective for sft/base; ignored by turbo) |
| `--cover_dit_model` | `acestep-v15-xl-base` | DiT used for `/cover` (cover is audio-to-audio; SFT/turbo only do text2music). Download with `acestep-download --model acestep-v15-xl-base` and load it on the API server |
| `--cover_noise_strength` | `0.4` | `cover_noise_strength` sent to ACE-Step for `/cover`: how faithfully the cover follows the source. ACE-Step truncates the denoising schedule at `t = 1 - cover_noise_strength` (`t=1` pure noise → drifts to the prompt / "nothing like the original"; `t=0` clean source → near-passthrough with artefacts). The good-cover range is ~0.3–0.5; `0.4` is the default. The mapping is non-linear (ACE-Step applies a timestep shift), so `≥0.6` leaves too few denoising steps and reproduces the source with quality loss — this was the old `0.7` default ("same song, worse quality"). Overridable per request via the `cover_noise` field (the bot's `/covernoise`) |
| `--lm_model` | (none) | 5Hz LM model, e.g. `acestep-5Hz-lm-1.7B` (only with `--thinking`) |
| `--thinking` | `false` | Use the 5Hz LM (CoT) to plan generation. Off by default (a missing/broken LM yields noise); enable only with a confirmed-working LM |
| `--acestep_format` | `wav` | Format requested from ACE-Step. The wrapper always re-encodes to OGG/Opus via the `ffmpeg` CLI (opus fails inside ACE-Step without matching FFmpeg libs + torchcodec) |
| `--api_key` | (none) | ACE-Step API key, if `ACESTEP_API_KEY` is set on the API server |
| `--timeout` | `600` | Max seconds to wait for one generation |

## Troubleshooting

### Output is just noise / static (SFT/base models) — DCW clipping

**This is a confirmed ACE-Step bug, now understood and fixed.** Through the `/release_task`
API, SFT/base models produce full-scale-clipping noise. Root cause (reproduced cross-platform,
including CPU/float32/SDPA — so it is *not* your GPU / flash-attention / bf16):

ACE-Step's **DCW** (Differential Correction in Wavelet domain) is a sampler refinement tuned
for the **turbo** distillation path. The API/service code path **hardcodes `dcw_enabled = True`**
(`acestep/inference.py`, `acestep/core/generation/handler/*`) with **no env var, no request
parameter, and no model gating**. On SFT/base models DCW inflates the latents ~3×
(`mean_abs` 0.06 → 0.22) and the resulting heavy clipping sounds like pure noise. The **Gradio
UI disables DCW for SFT/base** (turbo-only) — the API simply doesn't.

Diagnosis was ruled out against everything else first: `guidance_scale` (3.5 still noisy — DCW is
the cause, not CFG), the LM `thinking` path (off, still noisy), and flash-attention/Blackwell
(reproduces on CPU/SDPA, so it's not the GPU).

**The fix** mirrors the UI: gate DCW to turbo-only. A one-file patch + helper script ship here:

```bash
# From the conda env that runs acestep-api:
conda activate acestep
python inference-servers/ace-step/fix-dcw-clipping.py        # apply (idempotent)
python inference-servers/ace-step/fix-dcw-clipping.py --revert # undo
# then restart acestep-api
```

It patches `acestep/core/generation/handler/generate_music.py` to add, right after the existing
turbo guidance override:

```python
# DCW is tuned for the turbo distillation path; on SFT/base models it inflates the
# latents and clips the waveform to full-scale -> noise. Mirror the Gradio UI
# (model_config.get_ui_control_config) which only enables DCW for turbo.
if not self.is_turbo_model() and dcw_enabled:
    logger.info("[generate_music] Non-turbo model: disabling DCW "
                "(prevents full-scale clipping on SFT/base).")
    dcw_enabled = False
```

No wrapper/PHP change is needed — once ACE-Step stops running DCW on SFT, the DiT output is clean
(`mean_abs` ~0.06) and the wrapper's wav→OGG/Opus re-encode works as-is. Verified on
`acestep-v15-sft`: DCW-on `mean_abs` ≈ 0.22 (noise) vs DCW-off ≈ 0.06 (clean), at both 8 and 50
steps. The XL SFT model shares the identical code path, so the same fix applies.

This is worth filing upstream (the API path should gate DCW by model type just like the UI).

### Other checks

- Confirm the requested DiT is loaded: `curl http://localhost:8001/v1/models` should list your
  `--dit_model`. The model id is namespaced (e.g. `acestep/acestep-v15-xl-sft`); a bare-name
  mismatch just makes the server fall back to its primary (fine if that's the one you loaded).
- If you ever switch to the **turbo** DiT, DCW stays enabled (correct for turbo) — no patch
  effect. Pair with `--inference_steps 8`.

## Wire it into the bot

Add to `config.json` under `singModels` (the `url` points at this wrapper):

```jsonc
"singModels": {
  "HeartMuLa": {
    "url": "http://localhost:8212"
  },
  "Ace-Step-1.5-XL": {
    "url": "http://localhost:8213"
  },
  "Ace-Step-1.5-XL-Thinking": {
    "url": "http://localhost:8215"
  }
}
```

Then switch with `/singmodel Ace-Step-1.5-XL` and use `/sing` as usual:

```
/sing female vocal, synthwave, 80s, energetic, driving bass

[Verse 1]
Neon lights across the city skyline
...
```

### `/cover` (audio-to-audio cover)

Cover restyles an existing song from a new prompt while keeping its melody/structure.
Reply on a bot message that is itself a reply to an audio/voice/video/video-note, then
use `/cover` with the new style (and optionally new lyrics):

```
/cover female vocal, synthwave, 80s, driving bass

[Verse 1]
new lyrics here...
```

Add a separate entry under `coverModels` (the `url` still points at this wrapper):

```jsonc
"coverModels": {
  "Ace-Step-1.5-XL": {
    "url": "http://localhost:8213"
  }
}
```

Switch with `/covermodel Ace-Step-1.5-XL`. Two source-fidelity knobs (both: lower = drifts
toward the prompt / more restyle, higher = closer to the original):

- `/covernoise 0.4` → ACE-Step `cover_noise_strength` (0.0–1.0) — **the primary knob.** How
  faithfully the cover follows the source. ACE-Step truncates the denoising schedule at
  `t = 1 - cover_noise_strength`, so `0` = full denoise from pure noise (drifts, "nothing like
  the original"), `1` = clean source (near-passthrough with artefacts). The wrapper defaults to
  **0.4** (good-cover range ~0.3–0.5); lower (e.g. `0.3`) for a freer reinterpretation, raise
  (e.g. `0.5`) to keep more of the original. **Do not push it ≥0.6** — with the timestep shift
  that leaves too few denoising steps and you get the source back with quality loss.
- `/coverstrength 0.8` → ACE-Step `audio_cover_strength` (0.0–1.0) — secondary. The fraction of
  denoising steps that keep the source as conditioning; at the default `1.0` it always
  conditions on the source. Lower it (e.g. `0.4`) to hand the later steps to text2music-style
  conditioning for a more prompt-driven restyle. Only audible below `1.0`.

`/duration` and `/seed` are reused from `/sing`. Cover needs the **base** DiT, so on top of
the SFT setup above, also download and load it:

```bash
acestep-download --model acestep-v15-xl-base
# Launch the ACE-Step API server pointing at the base DiT (separate instance/port for cover):
ACESTEP_CONFIG_PATH=acestep-v15-xl-base acestep-api
# This wrapper, pointed at that server and told to use the base DiT for cover:
python main.py --port 8214 --cover_dit_model acestep-v15-xl-base
```

### `/sing` with LM thinking (`Ace-Step-1.5-XL-Thinking`)

ACE-Step's own guide calls the 5 Hz LM (`thinking`) the best-quality path: the LM plans the
semantic codes and metadata and the DiT renders richer detail than DiT-only. It is exposed
as a **separate `/singmodel`** — a second wrapper instance with `--thinking` — so the
default `Ace-Step-1.5-XL` stays DiT-only and you only pay for the LM when you select it.

The DiT is the same `acestep-v15-xl-sft`, so reuse the same API server and just load an LM
on it. Pick the LM by VRAM: `acestep-5Hz-lm-0.6B` (<12 GB), `acestep-5Hz-lm-1.7B`
(12–16 GB), `acestep-5Hz-lm-4B` (≥24 GB).

```bash
acestep-download --model acestep-5Hz-lm-1.7B

# Relaunch the ACE-Step API server with the LM loaded. It still serves the default,
# DiT-only model fine — `thinking` is selected per request by the wrapper:
ACESTEP_INIT_LLM=true ACESTEP_LM_MODEL_PATH=acestep-5Hz-lm-1.7B \
  ACESTEP_CONFIG_PATH=acestep-v15-xl-sft acestep-api

# A second wrapper instance, thinking on, on :8215:
python main.py --port 8215 --thinking true --lm_model acestep-5Hz-lm-1.7B
```

Add it under `singModels` (the `url` points at the thinking wrapper):

```jsonc
"Ace-Step-1.5-XL-Thinking": {
  "url": "http://localhost:8215"
}
```

Switch with `/singmodel Ace-Step-1.5-XL-Thinking`; `/duration` and `/seed` are reused.

> The loaded LM occupies VRAM even while the default DiT-only model is selected (it stays
> resident on the API server). To keep the default model's footprint minimal, run a
> **second** API server with the LM (`acestep-api` on another port) and point the thinking
> wrapper at it with `--acestep_api_url http://localhost:8002`.
> Only enable `--thinking` with a confirmed-working LM — a missing/broken one feeds garbage
> codes to the DiT and produces noise (see Troubleshooting).

## Request / response

`POST /txt_tags2music`

```json
{
  "tags": "female vocal, synthwave, energetic",
  "lyrics": "[Verse 1]\n...",
  "model": "Ace-Step-1.5-XL",
  "duration": 180000,
  "seed": 12345
}
```

- `tags` → ACE-Step `caption`/`prompt` (genre, instruments, mood, vocal style).
- `lyrics` → ACE-Step `lyrics` (supports structure tags like `[Verse]`, `[Chorus]`).
- `duration` is milliseconds; clamped to ACE-Step's 10–600s range. Omit to let the model decide.
- `seed` is optional; omitted → random seed.

`POST /cover`

```json
{
  "audio": "<base64 of the source song bytes>",
  "prompt": "female vocal, synthwave, 80s, driving bass",
  "lyrics": "[Verse 1]\noptional new lyrics",
  "cover_strength": 1.0,
  "cover_noise": 0.4,
  "duration": 180000,
  "seed": 12345
}
```

- `audio` → base64 of the source song (the message being covered). Required.
- `prompt` → ACE-Step `caption`/`prompt` (the new cover style). Required.
- `lyrics` → optional new lyrics; omit to keep ACE-Step's behaviour (no LM, so the new
  lyrics are used directly).
- `cover_strength` → ACE-Step `audio_cover_strength`, clamped to 0.0–1.0: fraction of
  denoising steps that keep the source as conditioning (1.0 = always, the cover default;
  lower → switches later steps to text2music conditioning → more prompt-driven restyle).
  Optional.
- `cover_noise` → ACE-Step `cover_noise_strength`, clamped to 0.0–1.0: **the primary
  fidelity knob.** How faithfully the cover follows the source. ACE-Step truncates the
  schedule at `t = 1 - cover_noise_strength`: 0 = drift to the prompt, 1 = near-passthrough.
  When omitted the wrapper applies its `--cover_noise_strength` default (0.4, good-cover
  range ~0.3–0.5). Don't send ≥0.6 — too few denoising steps → source back with artefacts.
- `duration` is milliseconds; clamped to 10–600s. Optional.
- `seed` is optional; omitted → random seed.

Cover maps to ACE-Step's async task via `task_type=cover` + `src_audio_path` (the wrapper
writes `audio` to a host-local temp file). The 5Hz LM is automatically skipped for cover.

Response (same shape for both endpoints):

```json
{
  "voice_data": "<base64 OGG/Opus audio>",
  "info": { "dit_model": "acestep-v15-xl-base", "metas": { "...": "..." } }
}
```
