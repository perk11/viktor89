#Based on https://github.com/comfyanonymous/ComfyUI/blob/master/script_examples/websockets_api_example_ws_images.py
import argparse
import base64
import json
import os
import sys
import threading
from pathlib import Path

from flask import Flask, request, jsonify

#Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))

from util.comfy import comfy_workflow_to_json_image_response
from util.image_resize import resize_if_needed

app = Flask(__name__)
parser = argparse.ArgumentParser(description="Inference server for icedit using ComfyUI")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument('--comfy_ui_input_dir', type=str, help='Path to ComfyUI "input" directory', required=True)
args = parser.parse_args()

sem = threading.Semaphore()

def get_img2img_workflow(input_image_filename, model, source_max_width, source_max_height):
    workflow_file_path = Path(__file__).with_name("comfy_workflow_img2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["1"]["inputs"]['model_name'] = model
    comfy_workflow_object["2"]["inputs"]['image'] = input_image_filename
    comfy_workflow_object["6"]["inputs"]['width'] = source_max_width
    comfy_workflow_object["6"]["inputs"]['height'] = source_max_height
    return comfy_workflow_object


def get_img2img_workflow_ldsr(input_image_filename: str, steps: str) -> dict:
    workflow_file_path = Path(__file__).with_name("comfy_workflow_img2img_ldsr.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["2"]["inputs"]['image'] = input_image_filename
    comfy_workflow_object["7"]["inputs"]['steps'] = steps
    return comfy_workflow_object
def get_img2img_workflow_seedvr2_sdxl(input_image_filename: str) -> dict:
    workflow_file_path = Path(__file__).with_name("comfy_workflow_seedvr2_sdxl.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["1123"]["inputs"]['image'] = input_image_filename
    return comfy_workflow_object
def get_img2img_workflow_seedvr2(input_image_filename: str) -> dict:
    workflow_file_path = Path(__file__).with_name("comfy_workflow_seedvr2.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["1123"]["inputs"]['image'] = input_image_filename
    return comfy_workflow_object
@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    model = data.get('model', '4x-ESRGAN.pth')
    source_max_width = data.get('source_max_width', 512)
    source_max_height = data.get('source_max_height', 512)
    init_images = data.get('init_images', [])

    data["init_images"]= "[omitted " + str(len(init_images)) + "]"
    print(data)
    if len(init_images) != 1:
        return jsonify({'error': "A single init image is required"}), 400

    image_data = base64.b64decode(init_images[0])

    input_image_file_name = 'viktor89-upscale.jpg'
    sem.acquire()

    if model == 'Flowty-LDSR':
        steps = int(data.get('steps', 100))
        possible_steps = [25, 50, 100, 250, 500, 1000]
        nearest_step_value = min(possible_steps, key=lambda t: (abs(steps - t), t))

        image_data, resized = resize_if_needed(image_data, source_max_width, source_max_height)
        if resized:
            print("Image was downscaled before upscaling", flush=True)
            input_image_file_name = 'viktor89-upscale.png'
        comfy_workflow_object = get_img2img_workflow_ldsr(input_image_file_name, str(nearest_step_value))
        infotext = f'Steps: {nearest_step_value}, Model: {model}'
    elif model == 'SeedVR2+SDXL':
        comfy_workflow_object = get_img2img_workflow_seedvr2_sdxl(input_image_file_name)
        infotext = f'Model: {model}'
    elif model == 'SeedVR2':
        comfy_workflow_object = get_img2img_workflow_seedvr2(input_image_file_name)
        infotext = f'Model: {model}'
    else:
        comfy_workflow_object = get_img2img_workflow(input_image_file_name, model, source_max_width, source_max_height)
        model_name = os.path.splitext(model)[0]
        infotext = f'Model: {model_name}'
    print(comfy_workflow_object)
    with open(args.comfy_ui_input_dir + '/' + input_image_file_name, 'wb') as file:
        file.write(image_data)
    try:
        return comfy_workflow_to_json_image_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    finally:
        sem.release()

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
