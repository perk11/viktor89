# audio-sr

HTTP wrapper around [AudioSR](https://github.com/haoheliu/versatile_audio_super_resolution)
— a diffusion-based audio super-resolution model that upscales any audio (any sample rate,
any content: music, speech, foley) to high-fidelity 48 kHz. The bot uses it as an optional
post-processing step in `/sing`: a `singModels` entry with `audioSR: true` sends the
generated song here to be enhanced before it is posted to Telegram.

This wrapper exposes the synchronous `{voice_data, info}` contract the bot's voice clients
expect (`POST /enhance`), so no PHP changes are needed beyond adding the `audioSuperResolutionUrl`
config key and `audioSR: true` on a `singModels` entry.

AudioSR always outputs 48 kHz PCM. This wrapper re-encodes the result to OGG/Opus (libopus)
via the **`ffmpeg` CLI** — the format Telegram requires for voice notes — so `ffmpeg` must be
installed on the host, exactly like the other audio servers (`tts`, `ace-step`, `sam-audio`).

## Installation

The model runs in-process in this wrapper (unlike `ace-step`, which talks to a separate API
server). One conda env holds AudioSR + the wrapper. We use pip throughout.

Requirements: **Python 3.9–3.11**, the **`ffmpeg` CLI** on PATH (the wrapper re-encodes the
output to OGG/Opus for Telegram), and **a CUDA GPU** (strongly recommended; CPU/MPS work but
are very slow for diffusion). The `basic` model needs ~6–8 GB VRAM.

Why conda: it pins a clean Python version (a plain `venv` just inherits your system Python),
and it matches this repo's other inference servers (e.g. `ace-step`, `rmbg`). PyTorch pip
wheels ship their own CUDA runtime, so you do **not** need conda's `cudatoolkit`.

### 1. Set up the env and install AudioSR + this wrapper

```bash
# conda env with a Python version AudioSR supports (upstream targets 3.9; 3.10/3.11 also work)
conda create -n audiosr -y python=3.10
conda activate audiosr
pip install torch torchaudio torchcodec matplotlib

# Install AudioSR from git (recommended): the git main branch adds
# super_resolution_long_audio — chunked, cross-faded processing used by this wrapper for
# songs longer than --long_audio_threshold_s (default 20s). The PyPI 0.0.7 release predates
# it; without it long songs run in a single (VRAM-heavy) pass instead of being chunked.
pip install git+https://github.com/haoheliu/versatile_audio_super_resolution.git
# Fallback (no long-audio chunking) if the git build's deps conflict with your torch:
#   pip install audiosr==0.0.7

# flask is the only extra this wrapper adds
pip install flask

# AudioSR pins an old librosa that imports pkg_resources. pkg_resources is part of
# setuptools, but setuptools>=82 (Feb 2026) REMOVED it — a plain `pip install setuptools`
# installs 82+ and the import still fails. Pin setuptools<81, which still ships it.
# Without this you get `ModuleNotFoundError: No module named 'pkg_resources'` on
# `import audiosr`.
pip install "setuptools<81"

# the wrapper re-encodes AudioSR's output to OGG/Opus for Telegram, so install the ffmpeg
# CLI. Either via the OS package manager:
#   sudo apt-get install -y ffmpeg
# or into the conda env:
#   conda install -c conda-forge ffmpeg
```

The AudioSR checkpoint (`basic` by default) **downloads automatically on first run** to
`~/.cache/audiosr/` (a few hundred MB). To pre-fetch it, run any `audiosr` command before
starting the server, e.g. `audiosr -i some.wav`.

### 2. Run the server

```bash
conda activate audiosr
cd /path/to/viktor89/inference-servers/audio-sr
python main.py --port 8240

# Sanity check it's up:
curl -s http://localhost:8240/enhance -X POST \
  -H 'Content-Type: application/json' \
  -d "{\"audio\": \"$(base64 -w0 some.ogg)\"}" \
  | head -c 200
```

Notes:
- On low-VRAM GPUs, keep `--long_audio_threshold_s` low (the chunked path caps peak VRAM by
  processing one `--chunk_duration_s` slice at a time); raise `--overlap_duration_s` for
  smoother chunk joins at the cost of more compute.
- `--device auto` picks CUDA if available, then MPS, then CPU. Force one with e.g.
  `--device cuda`.

Common options:

| Flag | Default | Description |
| --- | --- | --- |
| `--port` | (required) | Port this wrapper listens on |
| `--model_name` | `basic` | AudioSR checkpoint. `basic` = general (music/speech/fx); `speech` = tuned for speech |
| `--device` | `auto` | `auto` (cuda→mps→cpu), `cuda`, `mps`, or `cpu` |
| `--ddim_steps` | `50` | DDIM sampling steps. 50 is AudioSR's recommended default (faster than the older 200, no real quality loss for post-processing). Overridable per request |
| `--guidance_scale` | `3.5` | CFG guidance. Larger = closer to the (low-passed) input; smaller = more generative high-frequency detail. Overridable per request |
| `--long_audio_threshold_s` | `20` | Audio longer than this is processed in overlapping chunks (cross-faded) to avoid VRAM spikes. Shorter audio runs in one pass |
| `--chunk_duration_s` | `15` | Chunk length for the long-audio path |
| `--overlap_duration_s` | `2` | Overlap length (cross-faded) between chunks |

## Wire it into the bot

Add the top-level `audioSuperResolutionUrl` (pointing at this wrapper) and set `audioSR: true`
on any `singModels` entry you want enhanced:

```jsonc
"audioSuperResolutionUrl": "http://localhost:8240",
"singModels": {
  "Ace-Step-1.5-XL": {
    "url": "http://localhost:8213",
    "audioSR": true        // run the generated song through AudioSR before posting it
  },
  "HeartMuLa": {
    "url": "http://localhost:8212"   // audioSR omitted -> off for this model
  }
}
```

Then use `/sing` as usual. For an `audioSR: true` model the song is generated first, then
enhanced (you'll see the "Enhancing the song with AudioSR" status). If AudioSR fails, the
original song is still posted, so `/sing` never breaks because of this step.

`/seed` is reused: the same seed is forwarded to AudioSR, so a seeded `/sing` run is
reproducible end-to-end. No extra command is needed — AudioSR is purely a quality step, not
a separate model you select.

## Request / response

`POST /enhance`

```json
{
  "audio": "<base64 of the input audio bytes>",
  "seed": 12345,
  "ddim_steps": 50,
  "guidance_scale": 3.5
}
```

- `audio` → base64 of the input (any format ffmpeg/librosa can decode; the bot sends OGG/Opus
  voice notes). Required.
- `seed` → optional; omitted → random seed.
- `ddim_steps` / `guidance_scale` → optional per-request overrides of the server defaults.

Response:

```json
{
  "voice_data": "<base64 OGG/Opus audio at 48 kHz>",
  "info": {
    "model_name": "basic",
    "ddim_steps": 50,
    "guidance_scale": 3.5,
    "seed": 12345,
    "input_duration_s": 45.2
  }
}
```

On error (bad base64, unreadable input, AudioSR/ffmpeg failure) the wrapper returns a non-2xx
with `{"error": "..."}`.
