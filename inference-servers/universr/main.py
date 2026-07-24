"""
UniverSR (https://github.com/woongzip1/UniverSR) wrapper.

UniverSR is a vocoder-free flow-matching audio super-resolution model that upscales any
audio (speech, music, sound effects) from low input sample rates (8 / 12 / 16 / 24 kHz)
to high-fidelity 48 kHz. A single model handles all supported input rates.

This wrapper exposes the same synchronous `{voice_data, info}` contract the bot's voice
clients expect (`POST /enhance` — identical to the `audio-sr` / `tts` / `ace-step`
servers), so it can be wired in just like AudioSR.

Contract:
POST /enhance  {audio: <base64 of any audio>, ...optional params}
    -> 200 {voice_data: <base64 OGG/Opus>, info: {...}}
    -> 4xx/5xx {error: "..."} on failure

The input can be any format ffmpeg can decode (the bot sends OGG/Opus voice notes).
UniverSR always outputs 48 kHz mono PCM; this wrapper re-encodes the result to OGG/Opus
(libopus) via the ffmpeg CLI — the format Telegram requires for voice notes — so `ffmpeg`
must be installed on the host, exactly like the other audio servers.
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

from flask import Flask, request, jsonify

from universr import UniverSR

# Sample rates (Hz) UniverSR accepts as the effective input bandwidth (see inference.py).
SUPPORTED_INPUT_SR = (8000, 12000, 16000, 24000)
TARGET_SR = 48000

parser = argparse.ArgumentParser(description="UniverSR audio super-resolution server")
parser.add_argument(
    '--port', type=int, default=int(os.environ.get('PORT', '8241')),
    help='Port to listen on. Also read from the PORT env var. Default: 8241.',
)
parser.add_argument(
    '--host', type=str, default='localhost',
    help='Address to bind to. Defaults to localhost; set to 0.0.0.0 to listen on all '
         'interfaces (required when running inside a Docker container).',
)
parser.add_argument(
    '--model', type=str, default='woongzip1/universr-audio',
    help='HuggingFace repo id loaded via UniverSR.from_pretrained. '
         'woongzip1/universr-audio = general (music/speech/fx); '
         'woongzip1/universr-speech = speech-only. Ignored when --ckpt is given.',
)
parser.add_argument(
    '--ckpt', type=str, default=None,
    help='Local checkpoint file (.pth) loaded via UniverSR.from_local. '
         'Requires --config. Overrides --model.',
)
parser.add_argument(
    '--config', type=str, default=None,
    help='Path to the YAML config for the --ckpt checkpoint.',
)
parser.add_argument(
    '--device', type=str, default='auto',
    help='Device: auto (default, picks cuda→mps→cpu), cuda, mps, or cpu.',
)
parser.add_argument(
    '--input_sr', type=int, default=16000, choices=SUPPORTED_INPUT_SR,
    help='Default effective input bandwidth in Hz, used when a request omits input_sr '
         'and the input file is not already at a supported low rate (e.g. a 48 kHz file '
         'with limited bandwidth). 16000 assumes content up to ~8 kHz (typical for '
         'generated music/voice, e.g. ace-step output that "sounds like 16 kHz"). '
         '8000 = telephony-grade (4 kHz Nyquist), much more aggressive.',
)
parser.add_argument(
    '--max_chunk_duration_s', type=float, default=20.0,
    help='Audio longer than this is split into overlapping chunks (cross-faded) so a '
         'single forward pass never loads the whole clip into VRAM. 0 disables chunking.',
)
parser.add_argument(
    '--overlap_duration_s', type=float, default=2.0,
    help='Cross-faded overlap between chunks for the long-audio path.',
)
parser.add_argument(
    '--ode_method', type=str, default='midpoint', choices=['euler', 'midpoint', 'rk4'],
    help='ODE solver method. Overridable per request.',
)
parser.add_argument(
    '--ode_steps', type=int, default=4,
    help='Number of ODE integration steps. More = higher quality, slower. Overridable per request.',
)
parser.add_argument(
    '--guidance_scale', type=float, default=1.5,
    help='Classifier-free guidance scale. 0 / None disables CFG. Overridable per request.',
)
args = parser.parse_args()

app = Flask(__name__)

# UniverSR loads the full pipeline once; serialise requests so two /enhance calls don't
# contend for VRAM on the single pipeline.
semaphore = threading.Semaphore()


def resolve_device(device: str) -> str:
    if device != 'auto':
        return device
    if torch.cuda.is_available():
        return 'cuda'
    if getattr(torch.backends, 'mps', None) and torch.backends.mps.is_available():
        return 'mps'
    return 'cpu'


print(f"Loading UniverSR on device '{args.device}'...", flush=True)
device = resolve_device(args.device)
if args.ckpt:
    if not args.config:
        raise SystemExit('--config is required when --ckpt is given')
    model = UniverSR.from_local(
        ckpt_path=args.ckpt, config_path=args.config, device=device,
    )
else:
    model = UniverSR.from_pretrained(args.model, device=device)
print("UniverSR ready.", flush=True)


def ffprobe_duration(path: str) -> float:
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
    return float(proc.stdout.decode('utf-8', errors='replace').strip())


def ffprobe_sample_rate(path: str) -> int:
    # ffprobe (ships with ffmpeg) reads the actual stream sample rate, including OGG/Opus
    # (what the bot sends). torchaudio.info is backend-dependent and unreliable for Opus.
    proc = subprocess.run(
        ['ffprobe', '-v', 'error', '-select_streams', 'a:0',
         '-show_entries', 'stream=sample_rate',
         '-of', 'default=noprint_wrappers=1:nokey=1', path],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        raise RuntimeError(
            'ffprobe could not probe the input audio (is ffprobe installed?):\n'
            + proc.stderr.decode('utf-8', errors='replace')
        )
    return int(proc.stdout.decode('utf-8', errors='replace').strip())


def decode_to_wav(in_path: str) -> str:
    # torchaudio's OGG/Opus support is backend-dependent; decode to a plain mono WAV via
    # ffmpeg (preserving the native sample rate) so UniverSR can reliably read the real SR
    # and decide bandwidth limiting vs. resampling.
    wav_path = tempfile.NamedTemporaryFile(suffix='.wav', delete=False).name
    proc = subprocess.run(
        ['ffmpeg', '-y', '-i', in_path, '-vn', '-ac', '1', '-c:a', 'pcm_s16le', wav_path],
        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
    )
    if proc.returncode != 0:
        os.remove(wav_path)
        raise RuntimeError(
            'ffmpeg could not decode the input audio:\n'
            + proc.stderr.decode('utf-8', errors='replace')
        )
    return wav_path


def to_2d_waveform(wave) -> torch.Tensor:
    """Normalise UniverSR output to a [channels, samples] float32 CPU tensor."""
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
    torchaudio.save(wav_path, waveform, TARGET_SR)
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


def _resolve_input_sr(file_sr: int, request_sr) -> int | None:
    """Decide the effective bandwidth (Hz) to pass to UniverSR.enhance().

    - Explicit request value -> use it (already validated).
    - File's native SR is a supported low rate -> None (let UniverSR auto-detect it).
    - Otherwise (e.g. a 48 kHz file) -> fall back to the server default.
    """
    if request_sr is not None:
        return int(request_sr)
    if file_sr in SUPPORTED_INPUT_SR:
        return None
    return args.input_sr


def _run_enhance(wav_path, input_sr, ode_method, ode_steps, guidance_scale):
    """Single UniverSR.enhance() pass on a file path -> (1, T) @48 kHz tensor."""
    with torch.no_grad():
        out = model.enhance(
            wav_path,
            input_sr=input_sr,
            target_sr=TARGET_SR,
            ode_method=ode_method,
            ode_steps=ode_steps,
            guidance_scale=guidance_scale,
        )
    return to_2d_waveform(out)


def _enhance_audio(wav_path, file_sr, input_sr, ode_method, ode_steps, guidance_scale):
    """Super-resolve a WAV file, chunking long clips to cap VRAM.

    Returns (waveform (1, T) @48 kHz, num_chunks). UniverSR has no built-in chunked
    path, so for audio longer than --max_chunk_duration_s we split the input at the
    file's sample rate, run enhance() per chunk (each at file-level so UniverSR reads
    the real SR and bandwidth-limits correctly), then overlap-add the 48 kHz outputs
    with linear cross-fades. This keeps a single forward pass bounded by the chunk size.
    """
    try:
        duration_s = ffprobe_duration(wav_path)
    except Exception:
        duration_s = 0.0

    if args.max_chunk_duration_s <= 0 or duration_s <= args.max_chunk_duration_s:
        return _run_enhance(wav_path, input_sr, ode_method, ode_steps, guidance_scale), 1

    wav, sr = torchaudio.load(wav_path)
    if wav.shape[0] > 1:
        wav = wav.mean(dim=0, keepdim=True)
    total = wav.shape[-1]
    chunk_n = max(1, int(round(args.max_chunk_duration_s * sr)))
    if chunk_n >= total:
        return _run_enhance(wav_path, input_sr, ode_method, ode_steps, guidance_scale), 1
    overlap_n = max(1, int(round(args.overlap_duration_s * sr)))
    hop_n = chunk_n - overlap_n
    if hop_n <= 0:
        hop_n = max(1, chunk_n // 2)

    # Chunk start/end sample indices at the file's sample rate.
    positions = []
    pos = 0
    while pos < total:
        end = min(pos + chunk_n, total)
        positions.append((pos, end))
        if end >= total:
            break
        pos += hop_n
    num_chunks = len(positions)

    ratio = TARGET_SR / sr
    total_out = int(round(total * ratio))
    buf = torch.zeros(1, total_out)
    weight = torch.zeros(1, total_out)
    fade_out_samples = max(1, int(round(args.overlap_duration_s * TARGET_SR)))

    for k, (p0, p1) in enumerate(positions):
        chunk = wav[..., p0:p1]
        if chunk.shape[-1] < 1:
            continue
        with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as cf:
            chunk_path = cf.name
        try:
            torchaudio.save(chunk_path, chunk, sr)
            out_chunk = _run_enhance(
                chunk_path, input_sr, ode_method, ode_steps, guidance_scale,
            )
        finally:
            try:
                os.remove(chunk_path)
            except OSError:
                pass

        out_start = int(round(p0 * ratio))
        out_end = min(out_start + out_chunk.shape[-1], total_out)
        seg_len = out_end - out_start
        if seg_len <= 0:
            continue
        seg = out_chunk[..., :seg_len]

        w = torch.ones(seg_len)
        fade = min(seg_len // 2, fade_out_samples)
        if fade > 0:
            ramp = torch.linspace(0.0, 1.0, fade)
            if k > 0:                      # fade in over the overlap with the previous chunk
                w[:fade] = ramp
            if k < num_chunks - 1:         # fade out over the overlap with the next chunk
                w[-fade:] = ramp.flip(0)
        buf[:, out_start:out_end] += seg * w
        weight[:, out_start:out_end] += w

    weight = torch.clamp(weight, min=1e-8)
    return buf / weight, num_chunks


@app.route('/enhance', methods=['POST'])
def enhance():
    data = request.json or {}
    audio_b64 = data.get('audio')
    if not audio_b64:
        return jsonify({'error': 'audio (base64 of the input) is required'}), 400

    request_input_sr = data.get('input_sr')
    if request_input_sr is not None and int(request_input_sr) not in SUPPORTED_INPUT_SR:
        return jsonify({
            'error': f'input_sr must be one of {list(SUPPORTED_INPUT_SR)} Hz',
        }), 400

    # Per-request overrides for sampling params (server defaults otherwise).
    ode_method = data.get('ode_method', args.ode_method)
    if ode_method not in ('euler', 'midpoint', 'rk4'):
        return jsonify({'error': "ode_method must be one of 'euler', 'midpoint', 'rk4'"}), 400
    ode_steps = int(data.get('ode_steps', args.ode_steps))
    guidance_raw = data.get('guidance_scale', args.guidance_scale)
    # None / 0 disables CFG in UniverSR.
    guidance_scale = None if guidance_raw in (None, 0) else float(guidance_raw)

    try:
        audio_bytes = base64.b64decode(audio_b64)
    except Exception as e:
        return jsonify({'error': f'audio is not valid base64: {e}'}), 400

    with tempfile.NamedTemporaryFile(suffix='.src', delete=False) as in_f:
        in_f.write(audio_bytes)
        in_path = in_f.name
    wav_path = None
    with tempfile.NamedTemporaryFile(suffix='.ogg', delete=False) as out_f:
        out_path = out_f.name

    try:
        wav_path = decode_to_wav(in_path)
        file_sr = ffprobe_sample_rate(wav_path)
        input_sr = _resolve_input_sr(file_sr, request_input_sr)
    except Exception as e:
        print(f"Input handling failed: {e}", flush=True)
        _cleanup(in_path, out_path, wav_path)
        return jsonify({'error': f'could not read input audio ({e})'}), 400

    effective_sr = input_sr if input_sr is not None else file_sr
    print(
        f"Enhancing audio (native_sr={file_sr}, effective_bw={effective_sr} Hz, "
        f"ode={ode_method}/{ode_steps}, guidance={guidance_scale}, "
        f"model={'local' if args.ckpt else args.model})",
        flush=True,
    )
    semaphore.acquire()
    try:
        waveform, num_chunks = _enhance_audio(
            wav_path, file_sr, input_sr, ode_method, ode_steps, guidance_scale,
        )
    except Exception as e:
        print(f"UniverSR failed: {e}", flush=True)
        _cleanup(in_path, out_path, wav_path)
        return jsonify({'error': str(e)}), 500
    finally:
        semaphore.release()

    try:
        save_opus_ogg(waveform, out_path)
    except Exception as e:
        _cleanup(in_path, out_path, wav_path)
        return jsonify({'error': str(e)}), 500
    finally:
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()

    try:
        with open(out_path, 'rb') as f:
            audio_out = f.read()
    finally:
        _cleanup(in_path, out_path, wav_path)

    print(f"Enhanced audio -> {len(audio_out)} bytes of OGG/Opus", flush=True)
    return jsonify({
        'voice_data': base64.b64encode(audio_out).decode('utf-8'),
        'info': {
            'model': 'local' if args.ckpt else args.model,
            'input_sr': effective_sr,
            'target_sr': TARGET_SR,
            'ode_method': ode_method,
            'ode_steps': ode_steps,
            'guidance_scale': guidance_scale,
            'file_sample_rate': file_sr,
            'num_chunks': num_chunks,
        },
    })


def _cleanup(*paths: str) -> None:
    for p in paths:
        if p is None:
            continue
        try:
            os.remove(p)
        except OSError:
            pass


if __name__ == '__main__':
    app.run(host=args.host, port=args.port)
