import argparse
import base64
import json
import random
import sys
import threading
import traceback
from pathlib import Path

import websocket  # NOTE: websocket-client (https://github.com/websocket-client/websocket-client)
from flask import Flask, request, jsonify

# Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))

from util.comfy import comfy_workflow_to_json_video_response

parser = argparse.ArgumentParser(description="Inference server for Hunyuan-Video based on ComfyUI.")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument('--comfy_ui_input_dir', type=str, help='Path to ComfyUI "input" directory', required=True)
args = parser.parse_args()
app = Flask(__name__)

comfyui_server_address = args.comfy_ui_server_address
print(f"ComfyUI server address: {comfyui_server_address}")

comfyui_input_dir = args.comfy_ui_input_dir


@app.route('/txt2vid', methods=['POST'])
def generate_video():
    data = request.json
    print("Got new request")
    print(data, flush=True)

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt', None)
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', "(not specified)")
    width = int(data.get('width', 768))
    height = int(data.get('height', 512))
    steps = int(data.get('steps', 20))
    num_frames = int(data.get('num_frames', 121))

    try:
        match model:
            case 'kandinsky5-lite':
                comfy_workflow_object, infotext = get_workflow_and_infotext_kandinsky(prompt, negative_prompt, seed,
                                                                                      width, height, steps, num_frames)
            case _:
                return jsonify({"error": "Unknown model: " + model}), 400
        return comfy_workflow_to_json_video_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500


def get_workflow_and_infotext_kandinsky(prompt, negative_prompt, seed, width, height, steps, num_frames):
    workflow_file_path = Path(__file__).with_name("kandinsky5-lite-txt2vid.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["12:7"]["inputs"]["text"] = prompt
    if negative_prompt is not None:
        comfy_workflow_object["12:2"]["inputs"]["text"] = negative_prompt
    if width is not None:
        comfy_workflow_object["12:5"]["inputs"]["width"] = width
    if height is not None:
        comfy_workflow_object["12:5"]["inputs"]["height"] = height
    steps = min(steps, 50)
    comfy_workflow_object["12:8"]["inputs"]["steps"] = steps
    comfy_workflow_object["12:5"]["inputs"]["length"] = num_frames
    comfy_workflow_object["12:8"]["inputs"]["seed"] = seed

    return comfy_workflow_object, f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: kandinsky5-lite'


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
