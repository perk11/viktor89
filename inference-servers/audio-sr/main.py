"""
AudioSR (https://github.com/haoheliu/versatile_audio_super_resolution) wrapper.

AudioSR is a diffusion-based audio super-resolution model that upscales any audio
(any sample rate, any content) to high-fidelity 48 kHz. The bot uses it as an optional
post-processing step in /sing: a `singModels` entry with `audioSR: true` sends the
generated song here to be enhanced before it is posted to Telegram.

Contract (matches the bot's TtsApiResponse, same as the tts/ace-step servers):
POST /enhance  {audio: <base64 of any audio>, ...optional params}
    -> 200 {voice_data: <base64 OGG/Opus>, info: {...}}
    -> 4xx/5xx {error: "..."} on failure

The input can be any format ffmpeg/librosa can decode (the bot sends OGG/Opus voice
notes). AudioSR itself always outputs 48 kHz PCM; this wrapper re-encodes the result to
OGG/Opus (libopus) via the ffmpeg CLI — the format Telegram requires for voice notes —
so `ffmpeg` must be installed on the host, like the other audio servers.
"""

import argparse
import base64
import gc
import os
import subprocess
import tempfile
import threading

import numpy as np
import torch
import torchaudio

import audiosr

from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="AudioSR audio super-resolution server")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument(
    '--model_name', type=str, default='basic', choices=['basic', 'speech'],
    help='AudioSR checkpoint. "basic" is the general (music/speech/fx) model; '
         '"speech" is tuned for speech.',
)
parser.add_argument(
    '--device', type=str, default='auto',
    help='Device for AudioSR: auto (default), cuda, mps or cpu.',
)
parser.add_argument(
    '--ddim_steps', type=int, default=50,
    help='DDIM sampling steps. 50 is the AudioSR-recommended default (faster than the '
         '200 used in older docs with no real quality loss for post-processing).',
)
parser.add_argument(
    '--guidance_scale', type=float, default=3.5,
    help='CFG guidance. Larger = closer to the (low-passed) input; smaller = more '
         'generative high-frequency detail.',
)
parser.add_argument(
    '--long_audio_threshold_s', type=float, default=20.0,
    help='Audio longer than this is processed in overlapping chunks (cross-faded) to '
         'avoid running out of VRAM. Shorter audio runs in one pass.',
)
parser.add_argument(
    '--chunk_duration_s', type=float, default=15.0,
    help='Chunk length for the long-audio path.',
)
parser.add_argument(
    '--overlap_duration_s', type=float, default=2.0,
    help='Overlap length (cross-faded) between chunks for the long-audio path.',
)
args = parser.parse_args()

app = Flask(__name__)

# AudioSR loads the full pipeline once; serialise requests so two /enhance calls don't
# contend for VRAM on the single pipeline.
semaphore = threading.Semaphore()

print(f"Loading AudioSR model '{args.model_name}' on device '{args.device}'...", flush=True)
model = audiosr.build_model(device=args.device, model_name=args.model_name)
print("AudioSR ready.", flush=True)

# super_resolution_long_audio (chunked, cross-faded) was added after the 0.0.7 PyPI
# release. Import it defensively so the server still runs on older installs (long audio
# then falls back to a single pass).
super_resolution = audiosr.super_resolution
super_resolution_long_audio = getattr(audiosr, 'super_resolution_long_audio', None)


def audio_duration_seconds(path: str) -> float:
    # Probe via the ffprobe CLI (ships with the ffmpeg package, already a hard dependency).
    # This is more robust than torchaudio.info, which the soundfile-only backend builds
    # no longer provide, and it reads OGG/Opus (what the bot sends) without a backend dance.
    proc = subprocess.run(
        ['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
         '-of', 'default=noprint_wrappers=1:nokey=1', path],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        raise RuntimeError(
            'ffprobe could not probe the input audio (is ffprobe installed?):\n'
            + proc.stderr.decode('utf-8', errors='replace')
        )
    text = proc.stdout.decode('utf-8', errors='replace').strip()
    return float(text)


def to_2d_waveform(wave) -> torch.Tensor:
    """Normalise AudioSR output to a [channels, samples] float32 CPU tensor."""
    if isinstance(wave, np.ndarray):
        wave = torch.from_numpy(wave)
    wave = wave.detach().cpu().float()
    while wave.ndim > 2:
        wave = wave.squeeze(0)
    if wave.ndim == 1:
        wave = wave.unsqueeze(0)
    return wave


def save_opus_ogg(waveform: torch.Tensor, out_path: str) -> None:
    """Write a [channels, samples] @48kHz tensor to OGG/Opus via a wav + ffmpeg pass."""
    with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as wav_f:
        wav_path = wav_f.name
    torchaudio.save(wav_path, waveform, 48000)
    try:
        proc = subprocess.run(
            ['ffmpeg', '-y', '-i', wav_path, '-vn', '-c:a', 'libopus', out_path],
            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
        )
        if proc.returncode != 0:
            raise RuntimeError(
                'ffmpeg failed to encode OGG/Opus (is the ffmpeg CLI installed?):\n'
                + proc.stderr.decode('utf-8', errors='replace')
            )
    finally:
        try:
            os.remove(wav_path)
        except OSError:
            pass


def run_super_resolution(in_path: str, seed: int | None, duration_s: float) -> torch.Tensor:
    step_seed = 42 if seed is None else int(seed)
    if (
        super_resolution_long_audio is not None
        and duration_s > args.long_audio_threshold_s
    ):
        print(
            f"Audio is {duration_s:.1f}s > {args.long_audio_threshold_s}s -> chunked path",
            flush=True,
        )
        wave = super_resolution_long_audio(
            model,
            in_path,
            seed=step_seed,
            ddim_steps=args.ddim_steps,
            guidance_scale=args.guidance_scale,
            chunk_duration_s=args.chunk_duration_s,
            overlap_duration_s=args.overlap_duration_s,
        )
    else:
        wave = super_resolution(
            model,
            in_path,
            seed=step_seed,
            guidance_scale=args.guidance_scale,
            ddim_steps=args.ddim_steps,
        )
    return to_2d_waveform(wave)


@app.route('/enhance', methods=['POST'])
def enhance():
    data = request.json or {}
    audio_b64 = data.get('audio')
    if not audio_b64:
        return jsonify({'error': 'audio (base64 of the input) is required'}), 400

    seed = data.get('seed')
    # Per-request overrides for sampling params (server defaults otherwise).
    ddim_steps = data.get('ddim_steps')
    guidance_scale = data.get('guidance_scale')

    try:
        audio_bytes = base64.b64decode(audio_b64)
    except Exception as e:
        return jsonify({'error': f'audio is not valid base64: {e}'}), 400

    with tempfile.NamedTemporaryFile(suffix='.src', delete=False) as in_f:
        in_f.write(audio_bytes)
        in_path = in_f.name

    with tempfile.NamedTemporaryFile(suffix='.ogg', delete=False) as out_f:
        out_path = out_f.name

    try:
        duration_s = audio_duration_seconds(in_path)
    except Exception as e:
        _cleanup(in_path, out_path)
        return jsonify({'error': f'could not read input audio ({e})'}), 400

    saved_steps = args.ddim_steps
    saved_guidance = args.guidance_scale
    if ddim_steps is not None:
        args.ddim_steps = int(ddim_steps)
    if guidance_scale is not None:
        args.guidance_scale = float(guidance_scale)

    print(
        f"Enhancing {duration_s:.1f}s of audio (model={args.model_name}, "
        f"ddim_steps={args.ddim_steps}, guidance={args.guidance_scale}, seed={seed})",
        flush=True,
    )
    semaphore.acquire()
    try:
        with torch.no_grad():
            waveform = run_super_resolution(in_path, seed, duration_s)
    except Exception as e:
        print(f"AudioSR failed: {e}", flush=True)
        _cleanup(in_path, out_path)
        args.ddim_steps = saved_steps
        args.guidance_scale = saved_guidance
        return jsonify({'error': str(e)}), 500
    finally:
        semaphore.release()

    try:
        save_opus_ogg(waveform, out_path)
    except Exception as e:
        _cleanup(in_path, out_path)
        args.ddim_steps = saved_steps
        args.guidance_scale = saved_guidance
        return jsonify({'error': str(e)}), 500
    finally:
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()

    args.ddim_steps = saved_steps
    args.guidance_scale = saved_guidance

    try:
        with open(out_path, 'rb') as f:
            audio_out = f.read()
    finally:
        _cleanup(in_path, out_path)

    print(f"Enhanced audio -> {len(audio_out)} bytes of OGG/Opus", flush=True)
    return jsonify({
        'voice_data': base64.b64encode(audio_out).decode('utf-8'),
        'info': {
            'model_name': args.model_name,
            'ddim_steps': args.ddim_steps,
            'guidance_scale': args.guidance_scale,
            'seed': seed,
            'input_duration_s': duration_s,
        },
    })


def _cleanup(*paths: str) -> None:
    for p in paths:
        try:
            os.remove(p)
        except OSError:
            pass


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
