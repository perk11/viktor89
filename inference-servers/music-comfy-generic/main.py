import argparse
import base64
import json
import random
import sys
import threading
import urllib.error
import urllib.request
from pathlib import Path

from flask import Flask, request, jsonify

# Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))

from util.comfy import get_audio

parser = argparse.ArgumentParser(description="ComfyUI music generation server")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument(
    '--caption_server_address', type=str,
    help='address of the minimax-music3-caption server (inference-servers/minimax-music3-caption), '
         'used to write the Music 3 structured captions MiniMax Music 3 needs',
    default='localhost:8242',
)
args = parser.parse_args()

app = Flask(__name__)

semaphores = {}


@app.route('/txt_tags2music', methods=['POST'])
def generate_music():
    data = request.json
    print(data,flush=True)

    lyrics: str = data.get('lyrics')
    tags: str = data.get('tags')
    model: str = data.get('model')
    duration: int = data.get('duration', 240000)
    seed: int = data.get('seed', random.randint(1, 2 ** 32 - 1))
    if lyrics is None:
        return jsonify({'error': 'lyrics are required'}), 400
    if tags is None:
        return jsonify({'error': 'tags are required'}), 400
    # The caption rewrite is slow (minutes) and the caption server serializes its own
    # runs, so do it before taking the per-model lock.
    caption = None
    if is_minimax_music3(model):
        try:
            caption = get_minimax_music3_caption(tags, lyrics)
        except Exception as e:
            print(f"Caption generation failed: {e}", file=sys.stderr, flush=True)
            return jsonify({'error': f'Failed to generate MiniMax Music 3 caption: {e}'}), 502
    if model not in semaphores:
        semaphores[model] = threading.Semaphore()
    print("Acquiring lock for " + model, flush=True)
    semaphores[model].acquire()
    print("Acquired lock for " + model, flush=True)
    try:
        if is_minimax_music3(model):
            workflow = get_workflow_minimax_music3(lyrics, caption, duration, seed)
        else:
            workflow = get_workflow_heartmula(lyrics, tags, duration, seed)

        voice_data = get_audio(workflow, args.comfy_ui_server_address)[0]
        response = {
            'voice_data': base64.b64encode(voice_data).decode('utf-8'),
            'info': {
                'model': model,
            }
        }

        return jsonify(response)
    finally:
        semaphores[model].release()


def is_minimax_music3(model):
    return model == 'minimax-music-3'


def get_minimax_music3_caption(text, lyrics):
    address = args.caption_server_address.rstrip('/')
    if '://' not in address:
        address = 'http://' + address
    url = f'{address}/txt_lyrics2caption'
    body = json.dumps({'text': text, 'lyrics': lyrics}).encode('utf-8')
    req = urllib.request.Request(url, data=body, headers={'Content-Type': 'application/json'})
    print(f"Requesting Music 3 structured caption from {url}", flush=True)
    try:
        # Generous timeout: the caption server runs a full pi agent session (default skill timeout 600s)
        with urllib.request.urlopen(req, timeout=660) as response:
            result = json.loads(response.read())
    except urllib.error.HTTPError as e:
        raise RuntimeError(f'caption server returned HTTP {e.code}: {e.read().decode("utf-8", errors="replace")}') from e
    caption = result.get('caption')
    if not caption:
        raise RuntimeError('caption server response contained no caption')
    print(f"Caption generated ({len(caption)} chars): {caption}", flush=True)
    return caption


def get_workflow_minimax_music3(lyrics, caption, duration, seed):
    workflow_file_path = Path(__file__).with_name("minimax_music_3.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow_object = json.loads(workflow_file.read())
    text_encode_inputs = comfy_workflow_object["37:13"]['inputs']
    text_encode_inputs['caption'] = caption
    text_encode_inputs['lyrics'] = lyrics
    text_encode_inputs['max_duration'] = duration / 1000
    comfy_workflow_object["37:38"]['inputs']['seed'] = seed

    return comfy_workflow_object


def get_workflow_heartmula(lyrics, tags, duration, seed):
    workflow_file_path = Path(__file__).with_name("HeartMuLa.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["1"]["inputs"]['lyrics'] = lyrics
    comfy_workflow_object["1"]["inputs"]['tags'] = tags
    comfy_workflow_object["1"]["inputs"]['seed'] = seed
    comfy_workflow_object["1"]["inputs"]['max_audio_length_seconds'] = duration /1000

    return comfy_workflow_object


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
