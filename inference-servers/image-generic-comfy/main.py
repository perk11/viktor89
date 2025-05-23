import argparse
import json
import random
import sys
from pathlib import Path

from flask import Flask, request, jsonify

#Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))

from util.comfy import comfy_workflow_to_json_image_response

app = Flask(__name__)
parser = argparse.ArgumentParser(description="Inference using ComfyUI")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument('--comfy_ui_input_dir', type=str, help='Path to ComfyUI "input" directory', required=True)
args = parser.parse_args()


def get_txt2img_workflow_and_infotext_chroma(prompt, negative_prompt, seed, steps, width, height):
    workflow_file_path = Path(__file__).with_name("chroma-txt2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["4"]["inputs"]['text'] = prompt
    comfy_workflow_object["5"]["inputs"]['text'] = negative_prompt
    if steps > 0:
        comfy_workflow_object["9"]["inputs"]['steps'] = steps
    comfy_workflow_object["9"]["inputs"]['seed'] = seed
    comfy_workflow_object["14"]["inputs"]['width'] = width
    comfy_workflow_object["14"]["inputs"]['height'] = height
    return comfy_workflow_object, f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: chroma-unlocked-v31'


@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data, flush=True)

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', '(blank)')
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))
    steps = int(data.get('steps', 0))
    match model:
        case 'chroma-unlocked-v31':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_chroma(prompt, negative_prompt, seed, steps, width, height)
        case _:
            return jsonify({"error": "Unknown model: " + model}), 400

    return comfy_workflow_to_json_image_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
