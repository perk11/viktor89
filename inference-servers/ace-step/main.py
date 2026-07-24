"""
ACE-Step 1.5 XL wrapper for the bot's /sing and /cover commands.

ACE-Step 1.5 ships its own asynchronous REST API server (`uv run acestep-api`):
POST /release_task -> task_id, then poll POST /query_result, then GET /v1/audio.

This wrapper exposes the synchronous contract the bot's clients expect:
POST /txt_tags2music -> {voice_data: base64, info: {...}} (text2music for /sing), and
POST /cover           -> {voice_data: base64, info: {...}} (audio-to-audio cover). It
translates the bot's (tags, lyrics, duration in ms, seed) — or, for cover, the source
song + a new prompt/lyrics/strength — into an ACE-Step task and blocks until the audio
is ready. Audio is requested from ACE-Step as `wav` (its opus/torchcodec output path is
unreliable), then re-encoded to OGG/Opus with the ffmpeg CLI — the format Telegram
requires for voice notes.

Cover needs the base DiT (`--cover_dit_model`, default acestep-v15-xl-base); the SFT/turbo
DiTs only do text2music. Cover reads the source song from a host-local temp file, so this
wrapper must run on the same host as the acestep-api server.
"""

import argparse
import base64
import json
import os
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request

from flask import Flask, request, jsonify

app = Flask(__name__)

parser = argparse.ArgumentParser(description="ACE-Step 1.5 XL /sing wrapper")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument(
    '--acestep_api_url', type=str, default='http://localhost:8001',
    help='URL of the official ACE-Step API server (launched with `uv run acestep-api`)',
)
parser.add_argument(
    '--dit_model', type=str, default='acestep-v15-xl-sft',
    help='DiT model passed to ACE-Step. Must be loaded on the API server '
         '(via ACESTEP_CONFIG_PATH or /v1/init). XL variants: '
         'acestep-v15-xl-sft (highest quality, default) / '
         'acestep-v15-xl-turbo (fast, 8 steps) / acestep-v15-xl-base (all tasks)',
)
parser.add_argument(
    '--lm_model', type=str, default=None,
    help='Optional 5Hz LM model, e.g. acestep-5Hz-lm-1.7B (only used with --thinking)',
)
parser.add_argument('--api_key', type=str, default=None, help='Optional ACE-Step API key')
parser.add_argument(
    '--acestep_format', type=str, default='wav',
    help="Format requested from ACE-Step. wav is reliable; ACE-Step's opus/codec path "
         'needs FFmpeg libs + a torchcodec build matching torch. The wrapper always '
         're-encodes the result to OGG/Opus for Telegram via the ffmpeg CLI.',
)
parser.add_argument(
    '--thinking', type=lambda v: str(v).lower() in ('1', 'true', 'yes', 'on'), default=False,
    help="Use the 5Hz LM (CoT) to plan the generation. Matches the API server's own default "
         '(off). A missing/broken LM feeds garbage codes to the DiT and produces noise, so '
         'leave it off unless your LM is confirmed working.',
)
parser.add_argument(
    '--inference_steps', type=int, default=50,
    help='Diffusion steps. sft/base: 50 (recommended). turbo: 8.',
)
parser.add_argument(
    '--guidance_scale', type=float, default=7.0,
    help='CFG guidance strength (effective for sft/base; ignored by turbo)',
)
parser.add_argument(
    '--cover_dit_model', type=str, default='acestep-v15-xl-base',
    help='DiT model used for /cover tasks. Cover is an audio-to-audio task that only the '
         'base DiT supports (the SFT/turbo DiTs do text2music only). Download it with '
         '`acestep-download --model acestep-v15-xl-base` and load it on the API server '
         '(ACESTEP_CONFIG_PATH or /v1/init).',
)
parser.add_argument('--timeout', type=int, default=600, help='Max seconds to wait for one task')
parser.add_argument('--poll_interval', type=float, default=2.0, help='Seconds between status polls')
args = parser.parse_args()

# ACE-Step serializes generation through one pipeline; keep requests ordered.
semaphore = threading.Semaphore()


def acestep_request(path: str, payload: dict) -> dict:
    body = json.dumps(payload).encode('utf-8')
    req = urllib.request.Request(
        args.acestep_api_url.rstrip('/') + path,
        data=body,
        headers={'Content-Type': 'application/json'},
        method='POST',
    )
    if args.api_key:
        req.add_header('Authorization', 'Bearer ' + args.api_key)
    try:
        with urllib.request.urlopen(req) as resp:
            return json.loads(resp.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        detail = e.read().decode('utf-8', errors='replace')
        raise RuntimeError(f'ACE-Step {path} returned HTTP {e.code}: {detail}') from e


def release_task(params: dict) -> str:
    resp = acestep_request('/release_task', params)
    if resp.get('code') != 200 or resp.get('error'):
        raise RuntimeError(f'release_task failed: {resp.get("error") or resp}')
    task_id = resp.get('data', {}).get('task_id')
    if not task_id:
        raise RuntimeError(f'release_task returned no task_id: {resp}')
    return task_id


def query_result(task_id: str) -> dict:
    resp = acestep_request('/query_result', {'task_id_list': [task_id]})
    if resp.get('code') != 200 or resp.get('error'):
        raise RuntimeError(f'query_result failed: {resp.get("error") or resp}')
    data = resp.get('data') or []
    for entry in data:
        if entry.get('task_id') == task_id:
            return entry
    if data:
        return data[0]
    raise RuntimeError(f'task {task_id} not found in query_result response')


def wait_for_task(task_id: str) -> dict:
    deadline = time.time() + args.timeout
    while time.time() < deadline:
        entry = query_result(task_id)
        status = entry.get('status')
        # 0 = queued/running, 1 = succeeded, 2 = failed
        if status == 1:
            result = entry.get('result')
            if isinstance(result, str):
                result = json.loads(result)
            results = result if isinstance(result, list) else [result]
            for item in results:
                if isinstance(item, dict) and item.get('file'):
                    return item
            raise RuntimeError(f'task {task_id} succeeded but produced no audio file: {entry}')
        if status == 2:
            raise RuntimeError(f'task {task_id} failed: {entry}')
        time.sleep(args.poll_interval)
    raise RuntimeError(f'task {task_id} timed out after {args.timeout}s')


def download_audio(file_url: str) -> bytes:
    url = file_url
    if url.startswith('/'):
        url = args.acestep_api_url.rstrip('/') + url
    req = urllib.request.Request(url, method='GET')
    if args.api_key:
        req.add_header('Authorization', 'Bearer ' + args.api_key)
    try:
        with urllib.request.urlopen(req) as resp:
            return resp.read()
    except urllib.error.HTTPError as e:
        detail = e.read().decode('utf-8', errors='replace')
        raise RuntimeError(f'audio download HTTP {e.code}: {detail}') from e


def to_opus_ogg(audio: bytes, input_format: str) -> bytes:
    # Telegram voice notes must be OGG/Opus. ACE-Step can't be relied on to produce opus
    # (its torchcodec path needs FFmpeg libs + a matching torchcodec build), so we take a
    # dependable format (wav via soundfile) and re-encode here with the ffmpeg CLI — the
    # same approach as inference-servers/tts/txt2voice.py.
    with tempfile.NamedTemporaryFile(suffix='.' + input_format) as in_f, \
            tempfile.NamedTemporaryFile(suffix='.ogg') as out_f:
        in_f.write(audio)
        in_f.flush()
        proc = subprocess.run(
            ['ffmpeg', '-y', '-i', in_f.name, '-vn', '-c:a', 'libopus', out_f.name],
            stdout=subprocess.DEVNULL, stderr=subprocess.PIPE,
        )
        if proc.returncode != 0:
            raise RuntimeError(
                'ffmpeg failed to encode OGG/Opus (is the ffmpeg CLI installed?):\n'
                + proc.stderr.decode('utf-8', errors='replace')
            )
        with open(out_f.name, 'rb') as f:
            return f.read()


def submit_and_encode(params: dict) -> tuple[dict, bytes]:
    # Serialize ACE-Step's single pipeline; keep generations ordered.
    semaphore.acquire()
    try:
        task_id = release_task(params)
        print(f"Task {task_id} submitted, polling...", flush=True)
        result = wait_for_task(task_id)
        print(
            f"Task {task_id} -> dit_model={result.get('dit_model')!r} "
            f"lm_model={result.get('lm_model')!r} seed={result.get('seed_value')!r} "
            f"metas={result.get('metas')}",
            flush=True,
        )
        if result.get('generation_info'):
            print(f"generation_info: {result['generation_info']}", flush=True)
        audio = download_audio(result['file'])
        audio = to_opus_ogg(audio, args.acestep_format)
        print(f"Re-encoded {args.acestep_format} -> OGG/Opus ({len(audio)} bytes)", flush=True)
    finally:
        semaphore.release()
    return result, audio


def build_info(result: dict, fallbacks: dict) -> dict:
    return {
        'dit_model': result.get('dit_model', fallbacks.get('dit_model')),
        'lm_model': result.get('lm_model'),
        'metas': result.get('metas', {}),
        'seed_value': result.get('seed_value'),
        'prompt': result.get('prompt', fallbacks.get('prompt')),
        'lyrics': result.get('lyrics', fallbacks.get('lyrics')),
    }


def resolve_seed(params: dict, data: dict) -> None:
    # data['seed'] may be a bot integer or None; ACE-Step wants a bool+int pair.
    seed = data.get('seed')
    if seed is not None:
        params['use_random_seed'] = False
        params['seed'] = int(seed)
    else:
        params['use_random_seed'] = True


@app.route('/txt_tags2music', methods=['POST'])
def txt_tags2music():
    data = request.json or {}
    print(data, flush=True)

    lyrics: str = data.get('lyrics')
    tags: str = data.get('tags')
    # `model` is the bot's singModels key (e.g. "Ace-Step-1.5-XL"); ignored here
    # in favour of the DiT model configured via --dit_model on this wrapper.
    duration_ms = data.get('duration')

    if not tags:
        return jsonify({'error': 'tags (music description / caption) are required'}), 400
    if not lyrics:
        return jsonify({'error': 'lyrics are required'}), 400

    params = {
        'prompt': tags,
        'lyrics': lyrics,
        'audio_format': args.acestep_format,
        'batch_size': 1,
        'thinking': bool(args.thinking),
    }
    if args.dit_model:
        params['model'] = args.dit_model
    if args.thinking and args.lm_model:
        params['lm_model_path'] = args.lm_model
    if duration_ms:
        duration_seconds = max(10, min(600, int(duration_ms) / 1000))
        params['audio_duration'] = duration_seconds
    resolve_seed(params, data)
    params['inference_steps'] = args.inference_steps
    params['guidance_scale'] = args.guidance_scale

    print(
        f"Submitting ACE-Step task (dit={args.dit_model}, steps={args.inference_steps}, "
        f"cfg={args.guidance_scale}, thinking={args.thinking})",
        flush=True,
    )
    try:
        result, audio = submit_and_encode(params)
    except Exception as e:
        print(f"ACE-Step generation failed: {e}", flush=True)
        return jsonify({'error': str(e)}), 500

    info = build_info(result, {
        'dit_model': args.dit_model,
        'prompt': tags,
        'lyrics': lyrics,
    })
    return jsonify({'voice_data': base64.b64encode(audio).decode('utf-8'), 'info': info})


@app.route('/cover', methods=['POST'])
def cover():
    """Cover an audio file with ACE-Step (task_type=cover): keep the melody/structure,
    restyle it from a new prompt + optional new lyrics."""
    data = request.json or {}

    audio_b64 = data.get('audio')
    prompt = data.get('prompt')
    cover_strength = data.get('cover_strength')
    lyrics = data.get('lyrics')
    duration_ms = data.get('duration')

    if not audio_b64:
        return jsonify({'error': 'audio (base64 of the source song) is required'}), 400
    if not prompt:
        return jsonify({'error': 'prompt (the new cover style / caption) is required'}), 400

    try:
        audio_bytes = base64.b64decode(audio_b64)
    except Exception as e:
        return jsonify({'error': f'audio is not valid base64: {e}'}), 400

    # The bot sends the source song inline; ACE-Step reads it by absolute path, so we
    # spill it to a temp file (this wrapper and the acestep-api server share one host).
    with tempfile.NamedTemporaryFile(suffix='.src', delete=False) as src_f:
        src_f.write(audio_bytes)
        src_path = src_f.name

    params = {
        'task_type': 'cover',
        'src_audio_path': src_path,
        'prompt': prompt,
        'audio_format': args.acestep_format,
        'batch_size': 1,
        'model': args.cover_dit_model,
        'inference_steps': args.inference_steps,
        'guidance_scale': args.guidance_scale,
        # thinking/LM are automatically ignored by ACE-Step for cover tasks.
    }
    if lyrics:
        params['lyrics'] = lyrics
    if cover_strength is not None:
        try:
            strength = float(cover_strength)
        except (TypeError, ValueError):
            strength = 1.0
        params['audio_cover_strength'] = max(0.0, min(1.0, strength))
    if duration_ms:
        params['audio_duration'] = max(10, min(600, int(duration_ms) / 1000))
    resolve_seed(params, data)

    print(
        f"Submitting ACE-Step cover task (dit={args.cover_dit_model}, "
        f"strength={params.get('audio_cover_strength', 1.0)})",
        flush=True,
    )
    try:
        result, audio = submit_and_encode(params)
    except Exception as e:
        print(f"ACE-Step cover failed: {e}", flush=True)
        return jsonify({'error': str(e)}), 500
    finally:
        try:
            os.remove(src_path)
        except OSError:
            pass

    info = build_info(result, {
        'dit_model': args.cover_dit_model,
        'prompt': prompt,
        'lyrics': lyrics or '',
    })
    return jsonify({'voice_data': base64.b64encode(audio).decode('utf-8'), 'info': info})


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
