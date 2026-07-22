# mage-flow

Inference server for [microsoft/Mage-Flow](https://huggingface.co/microsoft/Mage-Flow),
a 4B native-resolution model for **text-to-image** and **instruction-based image
editing**. Exposes Automatic1111-compatible endpoints so the PHP
`Automatic1111APiClient` works with no dedicated client.

| Endpoint | Task | Load this repo (`--model_dir`) |
| --- | --- | --- |
| `/sdapi/v1/img2img` | image editing | `microsoft/Mage-Flow-Edit` / `-Base` / `-Turbo` |
| `/sdapi/v1/txt2img` | text-to-image | `microsoft/Mage-Flow` / `-Base` / `-Turbo` |

t2i and editing are **different checkpoints** sharing one codebase — launch one
server per checkpoint (on its own port) and call the matching endpoint.

~18–20 GB VRAM at 1024² on a single GPU.

## Install

From the [microsoft/Mage](https://github.com/microsoft/Mage) repo, `mage_flow/` is
an installable package. Use its pinned requirements, then install the package
itself, then `flash-attn` (build isolation **off** — it compiles against your
torch/CUDA):

```sh
cd mage_flow   # the package dir from microsoft/Mage
uv venv && source .venv/activate          # or: python -m venv .venv && source .venv/bin/activate

# torch matching your CUDA toolkit first (here cu126). Pick the wheel matching `nvcc`:
uv pip install torch==2.13.0 torchvision==0.28.0 --index-url https://download.pytorch.org/whl/cu126

uv pip install -r requirements.txt
uv pip install -e . --no-deps
uv pip install setuptools wheel ninja
uv pip install --no-build-isolation flash-attn==2.8.3
pip install flask                            # for the HTTP server
```

Plain `pip` works the same way (drop the `uv ` prefix). The HF repos auto-download
and cache on first use, so `--model_dir microsoft/Mage-Flow-Edit` works out of the
box (no manual download needed).

## Run

From this directory:

```sh
# image editing (priority endpoint)
python main.py --port 8140 --model_dir microsoft/Mage-Flow-Edit

# text-to-image
python main.py --port 8141 --model_dir microsoft/Mage-Flow

# Turbo variant (4 steps, cfg 1.0 — set in config.json, see below)
python main.py --port 8142 --model_dir microsoft/Mage-Flow-Edit-Turbo
```

Flags:

| Flag | Default | Meaning |
| --- | --- | --- |
| `--port` | — | port to listen on (required) |
| `--model_dir` | — | local dir or HF repo id (required) |
| `--device` | `cuda` | inference device |
| `--keep-filter` | off | keep Mage-Flow's built-in LLM content gate |

### Content filter

Mage-Flow ships a **mandatory** LLM content gate (`txt_enc.screen_text` /
`screen_edit`) that refuses prompts/images it classifies as
sexual / hate / self-harm / violence / copyright / public-figure, returning a blank
white placeholder. It is **disabled by default** here (the gate methods are
monkeypatched to a never-violating verdict) so nothing is refused. Pass
`--keep-filter` to restore the original behaviour.

## Configuration

Add entries to `config.json` under `imageModels`. `customUrl` points at the
server; set `img2img`/`txt2img` to scope which generation types the model accepts.
Recommended `steps`/`cfg_scale` per variant: Base & RL-aligned ≈ `30` steps /
`5.0` cfg; Turbo ≈ `4` steps / `1.0` cfg.

```jsonc
{
  // instruction-based editing (reply to a photo + describe the change)
  "mage-flow-edit": {
    "steps": 30,
    "cfg_scale": 5.0,
    "customUrl": "http://localhost:8140",
    "useOptions": false,
    "txt2img": false,
    "img2img": true
  },
  "mage-flow-edit-turbo": {
    "steps": 4,
    "cfg_scale": 1.0,
    "customUrl": "http://localhost:8142",
    "useOptions": false,
    "txt2img": false,
    "img2img": true
  },
  // text-to-image
  "mage-flow": {
    "width": 1024,
    "height": 1024,
    "steps": 20,
    "cfg_scale": 5.0,
    "customUrl": "http://localhost:8141",
    "useOptions": false
  }
}
```

## API

Standard Automatic1111 `sdapi` request/response. `init_images` are base64 PNGs.

**Edit** — `POST /sdapi/v1/img2img`:

```jsonc
{
  "prompt": "Replace the background with a field of sunflowers",
  "negative_prompt": " ",
  "init_images": ["<base64 png>"],
  "width": 1024, "height": 1024,   // optional; else derived from the source
  "steps": 30, "cfg_scale": 5.0, "seed": 12345
}
```

If `init_images` has 2–3 images they are all used as references for one edited
output (multi-image edit).

**Text-to-image** — `POST /sdapi/v1/txt2img`:

```jsonc
{
  "prompt": "A close-up portrait of an elderly African man, soft natural light.",
  "negative_prompt": " ",
  "width": 1024, "height": 1024,
  "steps": 20, "cfg_scale": 5.0, "seed": 12345
}
```

Response (both endpoints):

```jsonc
{
  "images": ["<base64 png>"],
  "parameters": {},
  "info": "{\"infotexts\": [\"...\"]}"
}
```

Output sizes are sanitized to a multiple of 16 within the native 512–2048 range.
