"""Pure (no Flask, no I/O) construction of ACE-Step cover-task parameters.

Kept in its own module so it can be unit-tested with the standard library,
without pulling in Flask or running the wrapper's HTTP layer.
"""


def clamp01(value):
    return max(0.0, min(1.0, value))


def build_cover_params(
    prompt,
    lyrics,
    cover_strength,
    cover_noise,
    duration_ms,
    *,
    dit_model,
    acestep_format,
    inference_steps,
    guidance_scale,
    default_cover_strength,
    default_cover_noise,
    seed=None,
):
    """Build the ACE-Step /release_task params for a cover task.

    The caller adds `src_audio_path` (a host-local file) before submitting.

    ``cover_strength`` / ``cover_noise`` fall back to the wrapper's CLI defaults
    when the caller omits them, so the bot sending neither yields the documented
    0.2/0.2 "style transfer" cover. Both are clamped to [0, 1].
    """
    try:
        strength = float(cover_strength) if cover_strength is not None else default_cover_strength
    except (TypeError, ValueError):
        strength = default_cover_strength
    try:
        noise = float(cover_noise) if cover_noise is not None else default_cover_noise
    except (TypeError, ValueError):
        noise = default_cover_noise

    params = {
        'task_type': 'cover',
        'prompt': prompt,
        'audio_format': acestep_format,
        'batch_size': 1,
        'model': dit_model,
        'inference_steps': inference_steps,
        'guidance_scale': guidance_scale,
        'audio_cover_strength': clamp01(strength),
        'cover_noise_strength': clamp01(noise),
    }
    if lyrics:
        params['lyrics'] = lyrics
    if duration_ms:
        params['audio_duration'] = max(10, min(600, int(duration_ms) / 1000))
    if seed is not None:
        params['use_random_seed'] = False
        params['seed'] = int(seed)
    else:
        params['use_random_seed'] = True
    return params
