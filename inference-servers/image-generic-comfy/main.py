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

from util.comfy import comfy_workflow_to_json_image_response

app = Flask(__name__)
parser = argparse.ArgumentParser(description="Inference using ComfyUI")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument('--comfy_ui_input_dir', type=str, help='Path to ComfyUI "input" directory', required=True)
args = parser.parse_args()


def get_txt2img_workflow_and_infotext_chroma(model, prompt, negative_prompt, seed, steps, width, height):
    workflow_file_path = Path(__file__).with_name("chroma-txt2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["25"]["inputs"]['unet_name'] = model + ".safetensors"
    comfy_workflow_object["4"]["inputs"]['text'] = prompt
    comfy_workflow_object["5"]["inputs"]['text'] = negative_prompt
    if steps > 0:
        comfy_workflow_object["9"]["inputs"]['steps'] = steps
    else:
        steps = comfy_workflow_object["9"]["inputs"]['steps']
    comfy_workflow_object["9"]["inputs"]['seed'] = seed
    comfy_workflow_object["14"]["inputs"]['width'] = width
    comfy_workflow_object["14"]["inputs"]['height'] = height
    return comfy_workflow_object, f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: ' + model

def get_txt2img_workflow_and_infotext_qwen(model, prompt, negative_prompt, seed, steps, width, height):
    workflow_file_path = Path(__file__).with_name("qwen-image_txt2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["37"]["inputs"]['unet_name'] = model + ".safetensors"
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    comfy_workflow_object["7"]["inputs"]['text'] = negative_prompt
    if steps > 0:
        comfy_workflow_object["3"]["inputs"]['steps'] = steps
    else:
        steps = comfy_workflow_object["3"]["inputs"]['steps']
    comfy_workflow_object["3"]["inputs"]['seed'] = seed
    comfy_workflow_object["58"]["inputs"]['width'] = width
    comfy_workflow_object["58"]["inputs"]['height'] = height
    return comfy_workflow_object, f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: ' + model

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
        case 'chroma-unlocked-v31' | 'chroma_v41LowStepRl':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_chroma(model, prompt, negative_prompt, seed, steps, width, height)
        case 'qwen_image_fp8_e4m3fn':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_qwen(model, prompt, negative_prompt, seed, steps, width, height)
        case _:
            return jsonify({"error": "Unknown model: " + model}), 400

    return comfy_workflow_to_json_image_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)

def get_img2img_workflow_infotext_and_filename_fluxkontext(prompt, seed, steps, width, height):
    if steps == 0:
        steps = 20
    input_image_filename = 'viktor89-fluxkontext.jpg'
    workflow_file_path = Path(__file__).with_name("flux-kontext-img2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    comfy_workflow_object["27"]["inputs"]['width'] = width
    comfy_workflow_object["27"]["inputs"]['width'] = height
    comfy_workflow_object["30"]["inputs"]['width'] = width
    comfy_workflow_object["30"]["inputs"]['height'] = height
    comfy_workflow_object["25"]["inputs"]['noise_seed'] = seed
    comfy_workflow_object["25"]["inputs"]['steps'] = steps

    comfy_workflow_object["41"]["inputs"]['image'] = input_image_filename
    return comfy_workflow_object,  f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: FLUX.1-Kontext-dev', input_image_filename


sem = threading.Semaphore() #Todo use a dict with semaphores based on input image file name
@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    model = data.get('model', '(blank)')
    prompt = data.get('prompt')
    steps = data.get('steps', 0)
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    init_images = data.get('init_images', [])

    data["init_images"]= "[omitted " + str(len(init_images)) + "]"
    print(data)
    images = []
    if len(init_images) != 1:
        return jsonify({'error': "A single init image is required"}), 400

    image_data = base64.b64decode(init_images[0])

    match model:
        case 'FLUX.1-Kontext-dev':
            comfy_workflow_object, infotext, input_image_file_name = get_img2img_workflow_infotext_and_filename_fluxkontext(prompt, seed, steps, width, height)
        case _:
            return jsonify({"error": "Unknown model: " + model}), 400
    sem.acquire()
    try:
        with open(args.comfy_ui_input_dir + '/' + input_image_file_name, 'wb') as file:
            file.write(image_data)
        return comfy_workflow_to_json_image_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    finally:
        sem.release()

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
