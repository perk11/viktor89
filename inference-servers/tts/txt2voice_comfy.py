import argparse
import base64
import json
import random
import sys
import threading
from pathlib import Path

from flask import Flask, request, jsonify


#Allow relative imports
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

sem = threading.Semaphore()


@app.route('/txt2voice', methods=['POST'])
def generate_voice():
    data = request.json

    prompt: str = data.get('prompt')
    model: str = data.get('model')
    seed: int = data.get('seed', random.randint(1, 2 ** 32 - 1))
    source_voice: str = data.get('source_voice')
    source_voice_format: str = data.get('source_voice_format')
    if prompt is None:
        return jsonify({'error': 'prompt is required'}), 400
    if source_voice is None:
        return jsonify({'error': 'source_voice is required'}), 400

    if source_voice_format not in ['wav', 'ogg', 'mp3']:
        return jsonify({'error': 'source_voice_format must be one of wav, ogg, mp3, got: ' + str(source_voice_format)},
                       400)

    sem.acquire()
    try:
        filename = args.comfy_ui_input_dir + '/viktor89_txt2voice_comfy.' + source_voice_format

        with open(filename, 'wb') as f:
            print("Writing received voice to " + filename)
            f.write(base64.b64decode(source_voice))
            f.flush()

        workflow = get_workflow_vibe_voice(prompt, seed, filename)
        voice_data = get_audio(workflow, args.comfy_ui_server_address)[0]

        response = {
            'voice_data': base64.b64encode(voice_data).decode('utf-8'),
            'info': {
                'prompt': prompt,
                'model': model,
                'seed': seed,
            }
        }

        return jsonify(response)
    finally:
        sem.release()

def get_workflow_vibe_voice(prompt, seed, input_filename):
    workflow_file_path = Path(__file__).with_name("VibeVoice.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["2"]["inputs"]['text'] = prompt
    comfy_workflow_object["2"]["inputs"]['seed'] = seed
    comfy_workflow_object["8"]["inputs"]['audio'] = input_filename

    return comfy_workflow_object

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
