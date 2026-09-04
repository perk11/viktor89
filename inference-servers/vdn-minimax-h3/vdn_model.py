"""Shared VDN-H3 model code for main.py and bake.py: CPU assembly of the render stack
(hybrid transform + LoRA merge + fp8 with released bf16 originals), baking that result
into a lean directory, and the fast load path for a baked directory.

Import only after the vdn repository root is on sys.path (both entrypoints insert it
before importing this module).
"""
import json
import os
import shutil
import time
from pathlib import Path

import torch
from diffusers import (AutoencoderKLMiniMaxH3, AutoencoderKLMiniMaxH3Audio,
                       MiniMaxH3Transformer3DModel)
from safetensors.torch import load_file, save_file

from src.checkpoints import load_checkpoint
from src.inference.assemble import InferenceModel, apply_ablation
from src.inference.lora import load_external_lora, merge_lora_state
from src.inference.render import load_models
from src.models.factory import load_model_weights
from src.models.hybrid_transform import (apply_hybrid_attention_transform, iter_hybrids,
                                         set_inference_mode, set_softmax_backend)
from src.models.ops.fp8_linear import Fp8Linear, convert_linear_to_fp8, per_tensor_gemm
from src.paths import H3_BASE, resolve_weights

BAKED_META_FILE = 'vdn_baked.json'
BAKED_WEIGHTS_FILE = 'model.fp8.safetensors'
BAKED_BUFFERS_FILE = 'buffers.safetensors'


class _ReleasedLinear(torch.nn.Module):
    """Stands in for the bf16 Linear that Fp8Linear retains for revert_fp8: the weights
    are released, but the bias survives, and an EMPTY bf16 `weight` parameter keeps
    module-dtype introspection (diffusers' get_parameter_dtype) reporting bfloat16 --
    exactly what the retained original provided. Without it Fp8Linear has no parameters
    at all, the first buffer (weight_fp8) is reported instead, and e.g. the
    transformer's context_embedder call casts its input to fp8, which then fails to
    promote against the bf16 bias."""

    def __init__(self, bias):
        super().__init__()
        self.weight = torch.nn.Parameter(torch.empty(0, dtype=torch.bfloat16),
                                         requires_grad=False)
        if bias is not None:
            self.bias = torch.nn.Parameter(bias.detach().clone(), requires_grad=False)
        else:
            self.bias = None


def drop_fp8_originals(transformer) -> int:
    """Release Fp8Linear's retained bf16 originals (about 62 GB across the stack); this
    server/baker never calls revert_fp8."""
    dropped = 0
    for module in transformer.modules():
        if isinstance(module, Fp8Linear):
            module.original = _ReleasedLinear(module.original.bias)
            dropped += 1
    return dropped


def patch_decomposed_for_sm120():
    """window_softmax_decomposed's windows leg calls flash_attn.cute (the FA4 CuteDSL
    varlen kernel), which faults on sm_120. Replace the function with the same plan
    but plain torch SDPA (flash-2/cuDNN kernels, which run fine there). Any failure
    still hits upstream's latch, which falls back to the flex path (dynamic, via
    force_dynamic_flex)."""
    from torch.nn.attention import SDPBackend, sdpa_kernel

    from src.models.softmax_attention import decomposed

    _offsets_cache = {}   # id(plan) -> (plan, cu_q list, cu_k list): plans are cached
    # per layout, and .tolist() on the CUDA offsets would sync the stream every layer;
    # the plan itself is held in the entry so an evicted plan's id can never be reused

    def window_softmax_decomposed_sdpa(query, key, value, layout, bounds, scale,
                                       anchor_frames="none"):
        plan = decomposed._plan(layout, bounds, anchor_frames, query.device)
        if not key.is_contiguous():
            key = key.contiguous()
        if not value.is_contiguous():
            value = value.contiguous()
        out = torch.empty(query.shape, dtype=query.dtype, device=query.device)
        backends = [SDPBackend.FLASH_ATTENTION, SDPBackend.CUDNN_ATTENTION]
        if len(plan.dense_q):
            with sdpa_kernel(backends):
                od = torch.nn.functional.scaled_dot_product_attention(
                    query[plan.dense_q].transpose(0, 1).unsqueeze(0),
                    key.transpose(0, 1).unsqueeze(0),
                    value.transpose(0, 1).unsqueeze(0), scale=scale)
            out[plan.dense_q] = od[0].transpose(0, 1)
        if plan.has_windows:
            q_win = query[plan.win_q]
            k_win = key[plan.kv_gather]
            v_win = value[plan.kv_gather]
            win_rows = plan.win_q
            cached = _offsets_cache.get(id(plan))
            if cached is None or cached[0] is not plan:
                cached = (plan, plan.cu_q.tolist(), plan.cu_k.tolist())
                _offsets_cache[id(plan)] = cached
                while len(_offsets_cache) > 8:
                    _offsets_cache.pop(next(iter(_offsets_cache)))
            _, cu_q, cu_k = cached
            with sdpa_kernel(backends):
                for i in range(len(cu_q) - 1):
                    qs, qe, ks, ke = cu_q[i], cu_q[i + 1], cu_k[i], cu_k[i + 1]
                    if qe <= qs:
                        continue
                    o = torch.nn.functional.scaled_dot_product_attention(
                        q_win[qs:qe].transpose(0, 1).unsqueeze(0),
                        k_win[ks:ke].transpose(0, 1).unsqueeze(0),
                        v_win[ks:ke].transpose(0, 1).unsqueeze(0), scale=scale)
                    out[win_rows[qs:qe]] = o[0].transpose(0, 1)
        return out

    decomposed.window_softmax_decomposed = window_softmax_decomposed_sdpa


def force_rowwise_fp8():
    """sm_120's fast torch._scaled_mm path is ROWWISE (as on sm90); the per-tensor
    choice per_tensor_gemm() makes for every capability >= 10 is a datacenter-Blackwell
    (sm100) path that runs slowly on sm_120. Forces the latch BEFORE any conversion:
    weight scales are baked as (1, out_features), so a bake and the server must agree
    (the loader's granularity check enforces that)."""
    import src.models.ops.fp8_linear as fp8_linear

    fp8_linear._PER_TENSOR = False


def force_dynamic_flex():
    """For window_softmax_flex's `inference=True` (the static CuteDSL/FLASH variant) to
    run the dynamic Triton variant instead, on GPUs whose CuteDSL kernels fault
    (sm_120). Wrapping the name in the calling module keeps every OTHER inference
    kernel enabled -- only the flex kernel is downgraded, the ~1.6x-on-that-leg path
    upstream itself falls back to when the CuteDSL template does not build."""
    import src.models.hybrid_attention as hybrid_attention

    original = hybrid_attention.window_softmax_flex
    if getattr(original, '_forced_dynamic', False):
        return

    def dynamic(*call_args, **call_kwargs):
        call_kwargs['inference'] = False
        return original(*call_args, **call_kwargs)

    dynamic._forced_dynamic = True
    hybrid_attention.window_softmax_flex = dynamic


class WeightOnlyFp8Linear(torch.nn.Module):
    """A Linear whose weight is stored fp8-e4m3 with a per-output-channel scale and
    upcast back on every call (weight-only quantisation, bf16 compute) -- the same
    layout ComfyUI ships for the MiniMax-H3 text encoder. Halves the ~62 GB encoder
    to ~31 GB so it fits on the GPU beside the render stack."""

    def __init__(self, linear):
        super().__init__()
        weight = linear.weight.data.float()
        scale = (weight.abs().amax(dim=1) / 448.0).clamp_min(1e-12)
        self.register_buffer('weight_fp8', (weight / scale.view(-1, 1)).to(torch.float8_e4m3fn))
        self.register_buffer('weight_scale', scale.view(-1, 1).to(torch.bfloat16))
        if linear.bias is not None:
            self.bias = torch.nn.Parameter(linear.bias.data, requires_grad=False)
        else:
            self.bias = None
        self.in_features = linear.in_features
        self.out_features = linear.out_features

    def forward(self, x):
        weight = self.weight_fp8.to(x.dtype)
        weight = weight.mul_(self.weight_scale.to(x.dtype))
        return torch.nn.functional.linear(x, weight, self.bias)


def quantize_linears_to_weight_only_fp8(module) -> int:
    """Swap every nn.Linear under `module` for WeightOnlyFp8Linear, releasing the
    bf16 weights as it goes. Works on a meta-device module too (all ops are
    shape-only there), so the baked loader can rebuild the structure."""
    replaced = 0
    for parent in module.modules():
        for attr, child in list(parent.named_children()):
            if isinstance(child, torch.nn.Linear):
                setattr(parent, attr, WeightOnlyFp8Linear(child))
                replaced += 1
    return replaced


def _non_persistent_buffers(module):
    persistent_names = set(module.state_dict().keys())
    found = {}
    for mname, sub in module.named_modules():
        prefix = f'{mname}.' if mname else ''
        for bname, buf in sub.named_buffers(recurse=False):
            if buf is not None and prefix + bname not in persistent_names:
                found[prefix + bname] = buf.detach().cpu()
    return found


def _restore_non_persistent_buffers(module, path: Path, device):
    if not path.exists():
        return
    for name, value in load_file(str(path)).items():
        owner_path, _, bname = name.rpartition('.')
        owner = module.get_submodule(owner_path) if owner_path else module
        setattr(owner, bname, value.to(device))


def save_baked_text_encoder(encoder, out_dir: Path) -> None:
    """Write the weight-only-fp8 text encoder so the server can load it directly:
    config.json + one safetensors (fp8 weights, scales, biases, norms, embeddings)
    + non-persistent buffers. Shared/tied tensors are deduped on the way out and
    re-shared by object identity on load."""
    te_dir = Path(out_dir) / 'text_encoder'
    te_dir.mkdir(parents=True, exist_ok=True)
    encoder.config.save_pretrained(str(te_dir))

    state = {key: value.contiguous() for key, value in encoder.state_dict().items()}
    unique, aliases = {}, {}
    seen = {}
    for key, value in state.items():
        pointer = value.data_ptr()
        if pointer in seen:
            aliases[key] = seen[pointer]
        else:
            seen[pointer] = key
            unique[key] = value
    weights_path = te_dir / BAKED_WEIGHTS_FILE
    print(f'Writing {weights_path}...', flush=True)
    save_file(unique, str(weights_path))
    if aliases:
        with open(te_dir / 'tensor_aliases.json', 'w') as f:
            json.dump(aliases, f, indent=2)
    non_persistent = _non_persistent_buffers(encoder)
    if non_persistent:
        print(f'Writing {len(non_persistent)} non-persistent buffer(s)...', flush=True)
        save_file(non_persistent, str(te_dir / BAKED_BUFFERS_FILE))


def load_baked_text_encoder(baked_dir, device='cpu'):
    """The meta-device counterpart of save_baked_text_encoder: rebuild the
    Qwen3VL architecture, swap in the fp8 Linears (structure-only on meta), assign
    the baked tensors. No bf16 checkpoint is ever read."""
    from transformers import AutoConfig, Qwen3VLForConditionalGeneration

    te_dir = Path(baked_dir) / 'text_encoder'
    config = AutoConfig.from_pretrained(str(te_dir))
    with torch.device('meta'):
        encoder = Qwen3VLForConditionalGeneration(config)
        quantize_linears_to_weight_only_fp8(encoder)

    state = load_file(str(te_dir / BAKED_WEIGHTS_FILE))
    aliases_path = te_dir / 'tensor_aliases.json'
    if aliases_path.exists():
        with open(aliases_path) as f:
            for alias, target in json.load(f).items():
                state[alias] = state[target]   # same object -> weights stay shared
    encoder.load_state_dict(state, strict=True, assign=True)
    del state
    _restore_non_persistent_buffers(encoder, te_dir / BAKED_BUFFERS_FILE, device)

    still_meta = [name for name, buf in encoder.named_buffers() if buf.is_meta]
    if still_meta:
        raise SystemExit(f'{te_dir}: buffers left on the meta device after loading '
                         f'({still_meta}); re-bake with the current bake.py')
    encoder.to(device).eval().requires_grad_(False)
    return encoder


def apply_sm120_adjustments(inference_kernels: bool = True):
    """The sm_120 adaptations shared by main.py and bake.py: decomposed windows
    re-routed to plain SDPA, and the failure latch's flex fallback forced to its
    dynamic Triton variant. Apply AFTER loading the model and BEFORE any forward,
    so compiled-kernel caches are produced by exactly the kernels the server runs."""
    patch_decomposed_for_sm120()
    if inference_kernels:
        force_dynamic_flex()
    print('sm_120 kernels: decomposed windows re-routed to plain SDPA; flex fallback '
          'forced to its dynamic Triton variant (CuteDSL faults on this GPU)', flush=True)


def assemble_render_stack(cfg, load_vae: bool = True) -> InferenceModel:
    """src/inference/assemble.py's build_inference_model, assembled on CPU and left
    there: upstream's order (load bf16 -> branch/LoRA merge -> fp8-quantise, all in GPU
    memory, with the bf16 originals retained next to the fp8 copies) peaks above
    100 GB of VRAM and OOMs on ~96 GB cards. Callers move the result to the GPU."""
    art = load_checkpoint(resolve_weights(cfg.checkpoint)) if cfg.checkpoint else None
    base_source = cfg.base_source or (art.model_spec['base']['source'] if art else H3_BASE)
    if load_vae:
        transformer, vae, audio_vae = load_models(base_source, 'cpu', vae_source=cfg.vae_source)
    else:
        transformer = MiniMaxH3Transformer3DModel.from_pretrained(
            resolve_weights(base_source), subfolder='transformer', torch_dtype=torch.bfloat16)
        vae = audio_vae = None

    merged = 0
    if art:
        apply_hybrid_attention_transform(transformer, art.model_spec['transforms'][0]['config'])
        branch_weights = {k: v for k, v in art.weights.items() if 'lora_' not in k}
        lora_weights = {k: v for k, v in art.weights.items() if 'lora_' in k}
        loaded = load_model_weights(transformer, branch_weights)
        merged = merge_lora_state(transformer, lora_weights) if lora_weights else 0
        print(f'built from spec: {loaded} branch tensors, {merged} LoRA pairs merged', flush=True)
    else:
        print('DENSE BASE render: no checkpoint -- no transform, no branch', flush=True)

    transformer.eval().requires_grad_(False)
    for xl in cfg.external_loras:
        state, scale, _ = load_external_lora(xl.path, xl.alpha)
        pairs = merge_lora_state(transformer, state, scale)
        print(f'external LoRA {xl.path}: {pairs} pairs merged (scale {scale:g})', flush=True)

    is_hybrid = next(iter_hybrids(transformer), None) is not None
    for attn in iter_hybrids(transformer):
        attn.teacher_mode = cfg.behavior.teacher_mode
        for prm in attn.parameters():
            if prm.dtype == torch.float32:
                prm.data = prm.data.to(torch.bfloat16)

    softmax_backend = None
    if is_hybrid:
        if cfg.kernels.inference_kernels:
            set_inference_mode(transformer, True)
        softmax_backend = set_softmax_backend(transformer, cfg.kernels.softmax_backend)
        print(f'window softmax: {softmax_backend}', flush=True)

    ablated = (apply_ablation(transformer, dict(cfg.ablation.overrides))
               if cfg.ablation.enabled and cfg.ablation.overrides else [])

    fp8_linears = 0
    if cfg.precision.fp8.enabled:
        fp8_linears = len(convert_linear_to_fp8(
            transformer, skip_end_blocks=cfg.precision.fp8.skip_end_blocks))
        dropped = drop_fp8_originals(transformer)
        print(f'fp8: {fp8_linears} Linears quantised, '
              f'{dropped} retained bf16 originals dropped', flush=True)

    return InferenceModel(transformer=transformer, vae=vae, audio_vae=audio_vae,
                          artifact=art, is_hybrid=is_hybrid, merged_lora_pairs=merged,
                          ablated=ablated, fp8_linears=fp8_linears,
                          softmax_backend=softmax_backend)


def move_to_device(model: InferenceModel, device) -> None:
    print(f'Moving the render stack to {device}...', flush=True)
    started = time.time()
    model.transformer.to(device)
    for module in (model.vae, model.audio_vae):
        if module is not None:
            module.to(device)
    print(f'Render stack on {device} in {time.time() - started:.0f}s', flush=True)


def _link_or_copy_tree(src: Path, dst: Path) -> None:
    """Hardlink when possible (same filesystem, bake output often sits next to the
    source), copy otherwise."""
    if not src.is_dir():
        raise SystemExit(f'missing {src}')
    copied = 0
    for file in src.rglob('*'):
        if not file.is_file():   # follows symlinks; dangling entries are skipped
            continue
        target = dst / file.relative_to(src)
        target.parent.mkdir(parents=True, exist_ok=True)
        try:
            # resolve() first: snapshot files are symlinks with targets RELATIVE to
            # the snapshot (../../blobs/...); hardlinking the symlink itself would
            # dangle from the bake's location. Hardlink the blob instead.
            os.link(file.resolve(), target)
        except OSError:
            shutil.copy2(file, target)
        copied += 1
    listing = sorted(p.name for p in src.iterdir())
    print(f'Copied {copied} file(s) {src} -> {dst} (entries: {listing})', flush=True)


def save_baked_model(model: InferenceModel, cfg, out_dir: Path) -> None:
    """Write the assembled stack so load_baked_stack() can skip the assembly: one fp8
    safetensors (weights + fp8 scales + released-original biases), the transformer's
    config.json, the transform/fp8 metadata, and the two VAE directories as-is."""
    out_dir = Path(out_dir)
    base_root = Path(resolve_weights(cfg.base_source or (
        model.artifact.model_spec['base']['source'] if model.artifact else H3_BASE)))

    transformer_dir = out_dir / 'transformer'
    transformer_dir.mkdir(parents=True, exist_ok=True)
    shutil.copy2(base_root / 'transformer' / 'config.json', transformer_dir / 'config.json')

    state = {key: value.contiguous() for key, value in model.transformer.state_dict().items()}
    weights_path = transformer_dir / BAKED_WEIGHTS_FILE
    print(f'Writing {weights_path}...', flush=True)
    save_file(state, str(weights_path))

    # load_state_dict skips non-persistent buffers (e.g. rope.inv_freq, computed at
    # __init__ rather than loaded), so persist them separately for the loader
    persistent_names = set(state.keys())
    del state
    non_persistent = {}
    for mname, module in model.transformer.named_modules():
        prefix = f'{mname}.' if mname else ''
        for bname, buf in module.named_buffers(recurse=False):
            if buf is not None and prefix + bname not in persistent_names:
                non_persistent[prefix + bname] = buf.detach().cpu()
    if non_persistent:
        print(f'Writing {len(non_persistent)} non-persistent buffer(s)...', flush=True)
        save_file(non_persistent, str(transformer_dir / BAKED_BUFFERS_FILE))

    meta = {
        'format': 1,
        'checkpoint': cfg.checkpoint,
        'base_source': str(base_root),
        'transform_config': (model.artifact.model_spec['transforms'][0]['config']
                             if model.artifact else None),
        'fp8': {
            'enabled': bool(cfg.precision.fp8.enabled),
            'skip_end_blocks': int(cfg.precision.fp8.skip_end_blocks),
            'per_tensor_gemm': bool(per_tensor_gemm()),
            'capability': list(torch.cuda.get_device_capability(0)) if torch.cuda.is_available() else None,
        },
        'fp8_linears': model.fp8_linears,
        'merged_lora_pairs': model.merged_lora_pairs,
    }
    with open(out_dir / BAKED_META_FILE, 'w') as f:
        json.dump(meta, f, indent=2, sort_keys=True)
        f.write('\n')

    for name in ('vae', 'audio_vae'):
        print(f'Copying {base_root / name} -> {out_dir / name}...', flush=True)
        _link_or_copy_tree(base_root / name, out_dir / name)


def load_baked_transformer(baked_dir, device, inference_kernels: bool = True,
                            softmax_backend: str = 'auto'):
    """Rebuild the architecture on the meta device (base config + hybrid transform +
    fp8 module swap, all structure-only) and assign the baked tensors into it. No bf16
    checkpoint is ever read."""
    baked_dir = Path(baked_dir)
    with open(baked_dir / BAKED_META_FILE) as f:
        meta = json.load(f)
    if meta.get('format') != 1:
        raise SystemExit(f'{baked_dir}: unknown baked format {meta.get("format")!r}')
    fp8_meta = meta['fp8']
    if fp8_meta['enabled'] and bool(per_tensor_gemm()) != fp8_meta['per_tensor_gemm']:
        raise SystemExit(f'{baked_dir}: fp8 was baked with '
                         + ('per-tensor' if fp8_meta['per_tensor_gemm'] else 'rowwise')
                         + ' scales for a different GPU architecture; re-bake on this machine')

    config = MiniMaxH3Transformer3DModel.load_config(str(baked_dir / 'transformer'))
    with torch.device('meta'):
        transformer = MiniMaxH3Transformer3DModel.from_config(config)
        if meta['transform_config']:
            apply_hybrid_attention_transform(transformer, meta['transform_config'])
        is_hybrid = next(iter_hybrids(transformer), None) is not None
        for attn in iter_hybrids(transformer):
            attn.teacher_mode = False   # cfg.behavior.teacher_mode, always False here
        if is_hybrid and fp8_meta['enabled']:
            set_inference_mode(transformer, inference_kernels)
            convert_linear_to_fp8(transformer, skip_end_blocks=fp8_meta['skip_end_blocks'])
            drop_fp8_originals(transformer)

    resolved = set_softmax_backend(transformer, softmax_backend) if is_hybrid else None
    print(f'window softmax: {resolved or softmax_backend} (inference kernels: {inference_kernels})', flush=True)
    softmax_backend = resolved
    state = load_file(str(baked_dir / 'transformer' / BAKED_WEIGHTS_FILE))
    transformer.load_state_dict(state, strict=True, assign=True)
    del state

    buffers_path = baked_dir / 'transformer' / BAKED_BUFFERS_FILE
    if buffers_path.exists():
        for name, value in load_file(str(buffers_path)).items():
            owner_path, _, bname = name.rpartition('.')
            owner = transformer.get_submodule(owner_path) if owner_path else transformer
            setattr(owner, bname, value.to(device))
    else:
        # legacy bake without the buffers file: the only non-persistent buffer in this
        # model is rope.inv_freq, recomputed here through diffusers' own module
        from diffusers.models.transformers.transformer_minimax_h3 import MiniMaxH3RotaryPosEmbed
        fresh = MiniMaxH3RotaryPosEmbed(
            rope_freq_dim=transformer.config.rope_freq_dim,
            rope_theta=transformer.config.rope_theta)
        transformer.rope.inv_freq = fresh.inv_freq.to(device)

    still_meta = [name for name, buf in transformer.named_buffers() if buf.is_meta]
    if still_meta:
        raise SystemExit(f'{baked_dir}: buffers left on the meta device after loading '
                         f'({still_meta}); re-bake with the current bake.py')

    transformer.to(device).eval().requires_grad_(False)
    return transformer, softmax_backend, is_hybrid


def load_baked_stack(baked_dir, device, inference_kernels: bool = True,
                    softmax_backend: str = 'auto') -> InferenceModel:
    print(f'Loading baked render stack from {baked_dir}...', flush=True)
    transformer, softmax_backend, is_hybrid = load_baked_transformer(
        baked_dir, device, inference_kernels, softmax_backend)
    vae = AutoencoderKLMiniMaxH3.from_pretrained(str(baked_dir), subfolder='vae').to(device)
    audio_vae = AutoencoderKLMiniMaxH3Audio.from_pretrained(str(baked_dir), subfolder='audio_vae').to(device)
    vae.eval()
    audio_vae.eval()
    return InferenceModel(transformer=transformer, vae=vae, audio_vae=audio_vae,
                          artifact=None, is_hybrid=is_hybrid,
                          softmax_backend=softmax_backend)
