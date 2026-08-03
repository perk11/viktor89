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
from util.comfy import comfy_workflow_vhs_video_combine_to_json_video_response

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
                vhs = False
                comfy_workflow_object, infotext = get_workflow_and_infotext_kandinsky(prompt, negative_prompt, seed,
                                                                                      width, height, steps, num_frames)
            case 'LTX-2.3-distilled':
                vhs = True
                comfy_workflow_object, infotext = get_workflow_and_infotext_ltx23(prompt, seed, num_frames)
            case 'minimax-h3':
                vhs = True
                comfy_workflow_object, infotext = get_workflow_and_infotext_minimaxh3(prompt, seed, num_frames)
            case _:
                return jsonify({"error": "Unknown model: " + model}), 400
        if vhs:
            return comfy_workflow_vhs_video_combine_to_json_video_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
        else:
            return comfy_workflow_to_json_video_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500


@app.route('/img2vid', methods=['POST'])
def generate_video_from_image():
    data = request.json
    data_for_logging = {k: v for k, v in data.items() if k != 'init_images'}
    data_for_logging['init_images'] = len(data.get('init_images', []))
    print("Got new img2vid request")
    print(data_for_logging, flush=True)

    init_images = data.get('init_images', [])
    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    num_frames = int(data.get('num_frames', 121))
    model = data.get('model', 'minimax-h3')

    if prompt is None:
        return jsonify({'error': "Prompt is required"}), 400
    if len(init_images) != 1:
        return jsonify({'error': "Exactly one init image is required."}), 400

    try:
        match model:
            case 'minimax-h3':
                image_data = base64.b64decode(init_images[0])
                image_file_name = "viktor89-minimax-h3-img2vid-image.jpg"
                with open(args.comfy_ui_input_dir + '/' + image_file_name, 'wb') as image_file:
                    image_file.write(image_data)
                comfy_workflow_object, infotext = get_workflow_and_infotext_minimaxh3_img2vid(
                    image_file_name, prompt, seed, num_frames)
            case _:
                return jsonify({"error": "Unknown model: " + model}), 400
        return comfy_workflow_vhs_video_combine_to_json_video_response(
            comfy_workflow_object, args.comfy_ui_server_address, infotext)
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
def get_workflow_and_infotext_ltx23(prompt, seed, num_frames):
    workflow_file_path = Path(__file__).with_name("ltx-2.3.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    num_frames = max(num_frames, 121)
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["187"]["inputs"]["prompt"] = prompt + "\n"

    comfy_workflow_object["166"]["inputs"]["value"] = num_frames
    comfy_workflow_object["189"]["inputs"]["noise_seed"] = seed

    return comfy_workflow_object, f'{prompt}\nSeed: {seed}, Model: ltx-2.3-22b-dev, Lora: ltx-23-22b-distilled-lora-384'
def get_workflow_and_infotext_minimaxh3(prompt, seed, num_frames):
    workflow_file_path = Path(__file__).with_name("minimax_h3_txt2vid.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    num_frames = min(num_frames, 360)
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["131"]["inputs"]["prompt"] = prompt

    comfy_workflow_object["132"]["inputs"]["value"] = num_frames/24
    comfy_workflow_object["130"]["inputs"]["noise_seed"] = seed

    return comfy_workflow_object, f'{prompt}\nSeed: {seed}, Model: minimax-h3'


def get_workflow_and_infotext_minimaxh3_img2vid(image_file_name, prompt, seed, num_frames):
    workflow_file_path = Path(__file__).with_name("minimax_h3_img2vid.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    num_frames = min(num_frames, 360)
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["114"]["inputs"]["image"] = image_file_name
    comfy_workflow_object["133"]["inputs"]["prompt"] = prompt
    comfy_workflow_object["131"]["inputs"]["noise_seed"] = seed
    comfy_workflow_object["135"]["inputs"]["value"] = num_frames / 24

    return comfy_workflow_object, f'{prompt}\nSeed: {seed}, Model: minimax-h3'
if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
