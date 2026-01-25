import argparse
import base64
import json
import random
import sys
import threading
from pathlib import Path

from flask import Flask, request, jsonify

# Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))

from util.comfy import get_audio

parser = argparse.ArgumentParser(description="text2voice comfyui server")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument('--comfy_ui_input_dir', type=str, help='Path to ComfyUI "input" directory', required=True)
args = parser.parse_args()

app = Flask(__name__)
root_dir = sys.argv[1]

semaphores = {}


@app.route('/txt_tags2music', methods=['POST'])
def generate_voice():
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
    if model not in semaphores:
        semaphores[model] = threading.Semaphore()
    print("Acquiring lock for " + model, flush=True)
    semaphores[model].acquire()
    print("Acquired lock for " + model, flush=True)
    try:
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
