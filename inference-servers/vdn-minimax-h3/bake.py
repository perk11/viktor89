"""One-time baker: assemble the VDN-H3 8-step render stack (hybrid transform + LoRA
merge + fp8, bf16 originals released) from an already-downloaded
OpenVDN/vdn-minimax-h3 weights tree and write a lean directory that main.py loads
directly via --baked_dir -- no per-startup assembly, and only the files actually used.
Run on the machine (and GPU architecture) that will serve the model.

    python3 bake.py --repo_dir /path/to/vdn-minimax-h3 \
        --out /path/to/vdn-minimax-h3/ckpts/baked-vdn-h3-8step

Output layout:
    transformer/config.json            copied from h3-base
    transformer/model.fp8.safetensors  the assembled stack, one shardless file
    vae/, audio_vae/                   copied (hardlinked when possible) from h3-base
    vdn_baked.json                     transform + fp8 metadata for the loader
"""
import argparse
import shutil
import sys
import time
from pathlib import Path

import torch

parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
parser.add_argument('--repo_dir', type=str, required=True,
                    help='Path to the vdn-minimax-h3 checkout (src/ + configs/)')
parser.add_argument('--source', type=str, default=None,
                    help='Weights root holding h3-base/ and stage-dmd-step-250/ '
                         '(default: <repo_dir>/ckpts)')
parser.add_argument('--out', type=str, required=True, help='Baked output directory')
parser.add_argument('--checkpoint', type=str, default=None,
                    help='8-step artifact (default: <source>/stage-dmd-step-250)')
parser.add_argument('--base_source', type=str, default=None,
                    help='Base weights root (default: <source>/h3-base)')
parser.add_argument('--delete_source', action='store_true',
                    help='After a successful bake and verification, delete h3-base/, '
                         'stage-dmd-step-250/ and stage-b-step-2000/ from --source')
parser.add_argument('--device', type=str, default='cuda:0',
                    help='Device for verification and kernel pre-compilation')
parser.add_argument('--skip_warmup', action='store_true',
                    help='Skip pre-compiling the kernels (Inductor/Triton caches) at the '
                         'default 345-frame 1344x768 shape')
parser.add_argument('--skip_text_encoder', action='store_true',
                    help='Skip baking the weight-only-fp8 text encoder + processor into the '
                         'output (the server then falls back to the bf16 Hub snapshot)')
parser.add_argument('--text_encoder_root', type=str, default=None,
                    help='A MiniMax-H3 snapshot with processor/ and text_encoder/ to bake '
                         'from (default: the cached Hub snapshot)')
args = parser.parse_args()

repo_dir = Path(args.repo_dir).resolve()
if not (repo_dir / 'src' / 'inference').is_dir():
    raise SystemExit(f'{repo_dir} is not a vdn-minimax-h3 checkout (no src/inference)')
sys.path.insert(0, str(repo_dir))
sys.path.insert(0, str(Path(__file__).resolve().parent))

from omegaconf import OmegaConf

import vdn_model
from src.config.inference import InferenceConfig, validate_ablation, validate_kernels
from src.inference.render import generate_latents, load_text
from src.paths import upstream_snapshot

if torch.cuda.is_available() and torch.cuda.get_device_capability(0)[0] >= 12:
    vdn_model.force_rowwise_fp8()
    print('sm_120+ GPU: fp8 scales forced to rowwise (the fast _scaled_mm path there)', flush=True)

source = Path(args.source).resolve() if args.source else repo_dir / 'ckpts'
checkpoint = Path(args.checkpoint).resolve() if args.checkpoint else source / 'stage-dmd-step-250'
base_source = Path(args.base_source).resolve() if args.base_source else source / 'h3-base'

cfg = OmegaConf.merge(
    OmegaConf.structured(InferenceConfig),
    OmegaConf.load(repo_dir / 'configs' / 'inference' / '8nfe_tuned_fp8.yaml'),
    OmegaConf.from_dotlist([
        f'checkpoint={checkpoint}',
        f'base_source={base_source}',
        'render.device=cuda:0',
        'render.num_steps=8',
        'render.warmup_steps=0',
        'render.prompt_file=(bake)',
        'render.out=(bake)',
    ]),
)
validate_ablation(cfg)
validate_kernels(cfg)
if cfg.render.num_steps != 8:
    raise SystemExit('this baker only serves the 8-step model')

started = time.time()
model = vdn_model.assemble_render_stack(cfg, load_vae=False)
vdn_model.save_baked_model(model, cfg, Path(args.out).resolve())
del model   # free the ~35 GB before the text encoder bake loads 62 GB more

print('Verifying the baked directory loads...', flush=True)
transformer, _, _ = vdn_model.load_baked_transformer(args.out, args.device)

if not args.skip_text_encoder:
    from transformers import Qwen3VLForConditionalGeneration, Qwen3VLProcessor

    te_root = Path(args.text_encoder_root) if args.text_encoder_root else Path(
        upstream_snapshot('processor', 'text_encoder'))
    print(f'Baking the text encoder from {te_root}...', flush=True)
    te_started = time.time()
    vdn_model._link_or_copy_tree(te_root / 'processor', Path(args.out).resolve() / 'processor')
    # load the processor from the COPY (no subfolder): transformers' local-dir
    # resolution ignores subfolder= for sub-components, so this also verifies the
    # copy is complete exactly the way the server will read it
    processor = Qwen3VLProcessor.from_pretrained(str(Path(args.out).resolve() / 'processor'))
    del processor
    encoder = Qwen3VLForConditionalGeneration.from_pretrained(
        str(te_root), subfolder='text_encoder', dtype=torch.bfloat16)
    replaced = vdn_model.quantize_linears_to_weight_only_fp8(encoder)
    vdn_model.save_baked_text_encoder(encoder, Path(args.out).resolve())
    del encoder
    print(f'Text encoder baked: {replaced} Linears in fp8, processor copied '
          f'({time.time() - te_started:.0f}s)', flush=True)
    print('Verifying the baked text encoder loads...', flush=True)
    vdn_model.load_baked_text_encoder(args.out, 'cpu')

if not args.skip_warmup:
    # Pre-compile on the SAME patched kernels the server runs, so the on-disk
    # Inductor/Triton caches (~/.cache/torch/inductor, ~/.triton/cache -- shared across
    # processes, keyed by code/shape/GPU/versions) are warm for the default shape.
    if torch.cuda.is_available() and torch.cuda.get_device_capability(args.device)[0] >= 12:
        vdn_model.apply_sm120_adjustments()
    torch.set_grad_enabled(False)
    example = repo_dir / 'prompts' / 'example_2.pt'
    if example.exists():
        prompt_embeds, text_token_tags = load_text(str(example), args.device)
    else:
        prompt_embeds = torch.randn(16, 5120, device=args.device, dtype=torch.bfloat16)
        text_token_tags = torch.ones(16, dtype=torch.long)
    num_frames = int(cfg.render.num_frames)
    print(f'Pre-compiling kernels: 2 NFE at {num_frames} frames, 1344x768 '
          f'(takes a few minutes, discarded)', flush=True)
    compile_started = time.time()
    generate_latents(
        transformer, prompt_embeds, text_token_tags, num_frames, 2, 42, args.device,
        video_shift=cfg.render.video_shift, audio_shift=cfg.render.audio_shift)
    print(f'Kernels compiled in {time.time() - compile_started:.0f}s', flush=True)

del transformer
torch.cuda.empty_cache()
print(f'Baked and verified in {time.time() - started:.0f}s: {args.out}', flush=True)

if args.delete_source:
    for name in ('h3-base', 'stage-dmd-step-250', 'stage-b-step-2000'):
        doomed = source / name
        if doomed.is_dir():
            print(f'Deleting {doomed}', flush=True)
            shutil.rmtree(doomed)

print(f"\nStart the server with:\n"
      f"  python3 {Path(__file__).with_name('main.py')} --repo_dir {repo_dir} "
      f"--baked_dir {Path(args.out).resolve()} --port 8080", flush=True)
