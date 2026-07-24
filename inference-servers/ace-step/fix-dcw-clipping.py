#!/usr/bin/env python3
"""
Fix for ACE-Step 1.5 producing noise (full-scale clipping) on SFT/base models
via the /release_task API.

ROOT CAUSE (proven, cross-platform incl. CPU/float32):
  DCW (Differential Correction in Wavelet domain) is a sampler refinement tuned
  for the TURBO distillation path. The API/service code path hardcodes
  `dcw_enabled = True` (acestep/inference.py, acestep/core/generation/handler/*)
  with NO env var, NO request parameter, and NO model gating. On SFT/base models
  DCW inflates the latents ~3x and the heavy clipping sounds like pure noise.
  The Gradio UI disables DCW for SFT/base (only turbo uses it) — the API does not.

FIX: mirror the UI — gate DCW to turbo-only inside AceStepHandler.generate_music.
Verified: drops mean_abs from ~0.22 (noise) to ~0.06 (clean) on acestep-v15-sft.

Run this against your acestep install (the env acestep-api runs from):
    python fix-acestep-dcw.py            # auto-locates the file
    python fix-acestep-dcw.py --revert   # undo
Idempotent. No PHP/wrapper changes needed; just restart acestep-api after.
"""
import argparse, sys
from pathlib import Path

try:
    import acestep.core.generation.handler.generate_music as gm
    path = Path(gm.__file__)
except Exception as e:
    print(f"Could not auto-locate generate_music.py ({e}).")
    print("Pass the path explicitly by editing this script's `path = ...` line.")
    sys.exit(1)

MARKER = "Non-turbo model: disabling DCW"
OLD = """            guidance_scale = 1.0

        # When LoRA is active, verify all decoder parameters are on the
"""
NEW = """            guidance_scale = 1.0

        # DCW is tuned for the turbo distillation path; on SFT/base models it
        # inflates the latents and clips the waveform to full-scale -> noise.
        # Mirror the Gradio UI (model_config.get_ui_control_config) which only
        # enables DCW for turbo.
        if not self.is_turbo_model() and dcw_enabled:
            logger.info(
                "[generate_music] Non-turbo model: disabling DCW "
                "(prevents full-scale clipping on SFT/base).",
            )
            dcw_enabled = False

        # When LoRA is active, verify all decoder parameters are on the
"""

revert = "--revert" in sys.argv

text = path.read_text()
print(f"File: {path}")

if revert:
    if MARKER not in text:
        print("Patch not present; nothing to revert.")
        sys.exit(0)
    text = text.replace(NEW, OLD)
    path.write_text(text)
    print("Reverted. Restart acestep-api.")
    sys.exit(0)

if MARKER in text:
    print("Already patched."); sys.exit(0)

if OLD not in text:
    print("Anchor not found (file structure differs from expected). Inspect manually:")
    print('look for the `if self.is_turbo_model() and guidance_scale != 1.0:` block.')
    sys.exit(2)

text = text.replace(OLD, NEW, 1)
path.write_text(text)
print("Patched successfully. Restart acestep-api (and the wrapper) and try /sing again.")
