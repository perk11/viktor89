# universr

HTTP wrapper around [UniverSR](https://github.com/woongzip1/UniverSR) — *Unified and
Versatile Audio Super-Resolution via Vocoder-Free Flow Matching* (ICASSP 2026). A single
model upscales any audio (speech, music, sound effects) from low input sample rates
(**8 / 12 / 16 / 24 kHz → 48 kHz**) directly in the complex STFT domain, with **no separate
neural vocoder**.

This wrapper exposes the synchronous `{voice_data, info}` contract the bot's voice clients
expect (`POST /enhance` — identical to the `audio-sr` / `tts` / `ace-step` servers), so it
can be wired in the same way as AudioSR, as an optional post-processing quality step.

UniverSR always outputs 48 kHz mono PCM. This wrapper re-encodes the result to OGG/Opus
(libopus) via the **`ffmpeg` CLI** — the format Telegram requires for voice notes — so
`ffmpeg` must be installed on the host, exactly like the other audio servers (`audio-sr`,
`tts`, `ace-step`, `sam-audio`).

## Installation

The model runs in-process in this wrapper. One conda env holds UniverSR + the wrapper. We
use pip throughout.

Requirements: **Python ≥ 3.10**, the **`ffmpeg` CLI** on PATH (the wrapper decodes input to
WAV and re-encodes the output to OGG/Opus for Telegram), and **a CUDA GPU** (strongly
recommended; CPU/MPS work but are much slower). UniverSR is a lightweight flow-matching
model (default 4 ODE steps), so VRAM needs are modest compared to diffusion-based AudioSR.

Why conda: it pins a clean Python version (a plain `venv` just inherits your system
Python), and it matches this repo's other inference servers (e.g. `audio-sr`, `rmbg`).
PyTorch pip wheels ship their own CUDA runtime, so you do **not** need conda's
`cudatoolkit`.

### 1. Set up the env and install UniverSR + this wrapper

```bash
# conda env with a Python version UniverSR supports (>=3.10)
conda create -n universr -y python=3.10
conda activate universr

# torch stack first (PyPI linux wheels are the CUDA builds and bundle their own CUDA runtime)
pip install torch torchaudio torchcodec

# UniverSR is not on PyPI — install from git
pip install git+https://github.com/woongzip1/UniverSR.git

# flask is the only extra this wrapper adds
pip install flask

# the wrapper decodes input to WAV and re-encodes output to OGG/Opus, so install the ffmpeg
# CLI. Either via the OS package manager:
#   sudo apt-get install -y ffmpeg
# or into the conda env:
#   conda install -c conda-forge ffmpeg
```

The UniverSR checkpoint (`woongzip1/universr-audio` by default) **downloads automatically
on first run** to `~/.cache/huggingface/` (a few hundred MB). To pre-fetch it:

```bash
huggingface-cli download woongzip1/universr-audio --local-dir ./ckpts/universr-audio
```

### Run in Docker (GPU)

The `Dockerfile` in this directory reproduces the conda env above and passes the host GPU
through with `--gpus all` (requires the
[nvidia-container-toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/)).
PyTorch wheels bundle their own CUDA runtime, so no CUDA toolkit is needed on the host —
only the NVIDIA driver.

```bash
# from the repo root
sudo docker build -t viktor89-universr inference-servers/universr

# --gpus all exposes the GPU. The checkpoint is stored in a named volume so it is
# downloaded once and reused across restarts.
sudo docker run --rm --gpus all -p 8241:8241 \
  -v universr-cache:/root/.cache/huggingface viktor89-universr

# The listening port is configurable via the PORT env var (default 8241).
# Remember to also map it with -p:
sudo docker run --rm --gpus all -e PORT=9000 -p 9000:9000 \
  -v universr-cache:/root/.cache/huggingface viktor89-universr

# Sanity check it's up:
curl -s http://localhost:8241/enhance -X POST \
  -H 'Content-Type: application/json' \
  -d "{\"audio\": \"$(base64 -w0 some.ogg)\", \"input_sr\": 16000}" \
  | head -c 200
```

The container binds `0.0.0.0:${PORT}`, so point the bot at the host port as usual
(`"audioSuperResolutionUrl": "http://localhost:8241"`). To override server flags, append
the full command (it replaces the image's default `CMD`), e.g.
`... viktor89-universr python main.py --host 0.0.0.0 --port 8241 --model woongzip1/universr-speech`.

### 2. Run the server

```bash
conda activate universr
cd /path/to/viktor89/inference-servers/universr
python main.py --port 8241

# Sanity check it's up:
curl -s http://localhost:8241/enhance -X POST \
  -H 'Content-Type: application/json' \
  -d "{\"audio\": \"$(base64 -w0 some.ogg)\", \"input_sr\": 16000}" \
  | head -c 200
```

Notes:
- `--device auto` picks CUDA if available, then MPS, then CPU. Force one with e.g.
  `--device cuda`.
- UniverSR has no built-in chunking, so this wrapper splits long clips into overlapping
  chunks (`--max_chunk_duration_s`, cross-faded with `--overlap_duration_s`) to keep each
  forward pass — and thus peak VRAM — bounded by the chunk size. On a low-VRAM GPU, lower
  `--max_chunk_duration_s`; raise `--overlap_duration_s` for smoother joins.

Common options:

| Flag | Default | Description |
| --- | --- | --- |
| `--port` | `8241` (or `$PORT`) | Port this wrapper listens on. Also read from the `PORT` env var |
| `--host` | `localhost` | Address to bind to. Set to `0.0.0.0` to listen on all interfaces (required inside Docker) |
| `--model` | `woongzip1/universr-audio` | HuggingFace repo id. `woongzip1/universr-audio` = general (music/speech/fx); `woongzip1/universr-speech` = speech-only |
| `--ckpt` | *(none)* | Local `.pth` checkpoint (loaded via `UniverSR.from_local`, requires `--config`). Overrides `--model` |
| `--config` | *(none)* | YAML config for `--ckpt` |
| `--device` | `auto` | `auto` (cuda→mps→cpu), `cuda`, `mps`, or `cpu` |
| `--input_sr` | `16000` | Default effective input bandwidth in Hz (8000/12000/16000/24000), used when a request omits `input_sr` and the input file is not already at a supported low rate. `16000` assumes content up to ~8 kHz (typical for generated music/voice); `8000` is telephony-grade (4 kHz Nyquist) |
| `--ode_method` | `midpoint` | ODE solver: `euler`, `midpoint`, `rk4`. Overridable per request |
| `--ode_steps` | `4` | Number of ODE integration steps. More = higher quality, slower. Overridable per request |
| `--guidance_scale` | `1.5` | Classifier-free guidance. `0`/omitted disables CFG. Overridable per request |
| `--max_chunk_duration_s` | `20` | Audio longer than this is split into overlapping chunks (cross-faded) so one forward pass never loads the whole clip into VRAM. `0` disables chunking |
| `--overlap_duration_s` | `2` | Cross-faded overlap between chunks |

## Wire it into the bot

This wrapper uses the same `{voice_data, info}` contract as the `audio-sr` server, so wire
it up the same way: point a top-level `audioSuperResolutionUrl` at this wrapper and set
`audioSR: true` on any `singModels` entry you want enhanced:

```jsonc
"audioSuperResolutionUrl": "http://localhost:8241",
"singModels": {
  "Ace-Step-1.5-XL": {
    "url": "http://localhost:8213",
    "audioSR": true        // run the generated song through the configured SR server before posting it
  }
}
```

## How `input_sr` is chosen

UniverSR needs the **effective bandwidth** of the input (one of 8000/12000/16000/24000 Hz),
not necessarily the file's actual sample rate. The wrapper decides it per request:

1. If the request sends `input_sr`, that value is used (must be in the supported set).
2. Otherwise, if the input file's native sample rate *is* a supported low rate (e.g. a real
   8 kHz file), UniverSR auto-detects it.
3. Otherwise (e.g. a 48 kHz file with limited bandwidth — typical for generated music
   like **ace-step output, or Telegram Opus voice notes**), the server default
   (`--input_sr`, `16000`) is used and the input is bandwidth-limited (downsample→
   upsample, matching the training degradation) before super-resolution.

So a 48 kHz ace-step song that "sounds like 16 kHz" (content up to ~8 kHz) is, by default,
banded to 8 kHz and reconstructed to 48 kHz — which is exactly UniverSR's recommended use
for such files (the `16000` setting, Nyquist 8 kHz, matches the perceived bandwidth). Send
a different `input_sr` to override:

- `input_sr=8000` (Nyquist 4 kHz): only if the content is genuinely telephony-grade; very
  aggressive — it discards everything above 4 kHz before regenerating it.
- `input_sr=16000` (Nyquist 8 kHz): the default — best for generated music/voice that
  "sounds like 16 kHz".
- `input_sr=24000` (Nyquist 12 kHz): if the content extends higher, though this path is
  the weakest per the UniverSR README.

## Request / response

`POST /enhance`

```json
{
  "audio": "<base64 of the input audio bytes>",
  "input_sr": 16000,
  "ode_method": "midpoint",
  "ode_steps": 4,
  "guidance_scale": 1.5
}
```

- `audio` → base64 of the input (any format ffmpeg can decode; the bot sends OGG/Opus voice
  notes). Required.
- `input_sr` → optional effective bandwidth in Hz (8000/12000/16000/24000). See above.
- `ode_method` / `ode_steps` / `guidance_scale` → optional per-request overrides of the
  server defaults.

Response:

```json
{
  "voice_data": "<base64 OGG/Opus audio at 48 kHz>",
  "info": {
    "model": "woongzip1/universr-audio",
    "input_sr": 16000,
    "target_sr": 48000,
    "ode_method": "midpoint",
    "ode_steps": 4,
    "guidance_scale": 1.5,
    "file_sample_rate": 48000
  }
}
```

On error (bad base64, unreadable input, unsupported `input_sr`/`ode_method`, UniverSR or
ffmpeg failure) the wrapper returns a non-2xx with `{"error": "..."}`.

## Tips

Guidance scale trades off objective fidelity for perceptual richness (from the UniverSR
README):

| Domain | Recommended `guidance_scale` |
| --- | --- |
| Speech | 1.0 – 1.5 |
| Music | 1.5 – 2.0 |
| Sound effects | 1.5 |

The 8 kHz → 48 kHz path is the strongest (the training mix is 70% 8 kHz); higher input rates
(especially 24 kHz) may show weaker high-frequency reconstruction. See the UniverSR README
for details.
