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

semaphores = {}
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
    if len(init_images) < 1:
        return jsonify({"error": "At least a single image required in init_images"}), 400
    if len(init_images) > 10:
        return jsonify({"error": "Too many init images"}), 400
    if model not in semaphores:
        semaphores[model] = threading.Semaphore()

    print("Acquiring lock for " + model, flush=True)
    semaphores[model].acquire()
    print("Acquired lock for " + model, flush=True)
    image_filenames = []
    try:
        for index, image in enumerate(init_images):
            image_data = base64.b64decode(image)
            file_name = "viktor89-" + model + '-image-' +  str(index) + '.jpg'
            image_filenames.append(file_name)
            with open(args.comfy_ui_input_dir + '/' + file_name, 'wb') as image_file:
                image_file.write(image_data)

        match model:
            case 'Qwen-Image-Edit-2509-Q8_0':
                comfy_workflow_object, infotext = get_img2img_workflow_infotext_and_filename_qwen_image_edit2509(image_filenames, prompt, seed, steps)
            case 'Qwen-Image-Edit-MeiTu':
                comfy_workflow_object, infotext = get_img2img_workflow_infotext_and_filename_qwen_image_edit_meitu(image_filenames, prompt, seed, steps)
            case 'flux2_dev_fp8':
                comfy_workflow_object, infotext = get_img2img_workflow_infotext_and_filename_flux2(image_filenames, prompt, seed, steps)
            case _:
                return jsonify({"error": "Unknown model: " + model}), 400
        return comfy_workflow_to_json_image_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    finally:
        semaphores[model].release()

def get_img2img_workflow_infotext_and_filename_qwen_image_edit2509(image_filenames, prompt, seed, steps):
    if len(image_filenames) > 3:
        raise Exception("qwen_image_edit2509 supports up to 3 images")
    if steps == 0:
        steps = 20
    steps = min(30, int(steps))
    workflow_file_path = Path(__file__).with_name("qwen-image2509-img2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["134"]["inputs"]['prompt'] = prompt
    comfy_workflow_object["25"]["inputs"]['noise_seed'] = seed
    comfy_workflow_object["17"]["inputs"]['steps'] = steps

    comfy_workflow_object["127"]["inputs"]['image'] = image_filenames[0]
    if len(image_filenames) > 1:
        comfy_workflow_object["138"]["inputs"]['image'] = image_filenames[1]
    else:
        del comfy_workflow_object["138"]
        del comfy_workflow_object["139"]
        del comfy_workflow_object["140"]
        del comfy_workflow_object["141"]["inputs"]['image2']
        del comfy_workflow_object["134"]["inputs"]['image2']
    if len(image_filenames) > 2:
        comfy_workflow_object["142"]["inputs"]['image'] = image_filenames[2]
    else:
        del comfy_workflow_object["142"]
        del comfy_workflow_object["143"]
        del comfy_workflow_object["144"]
        del comfy_workflow_object["141"]["inputs"]['image3']
        del comfy_workflow_object["134"]["inputs"]['image3']

    return comfy_workflow_object,  f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: Qwen-Image-Edit-2509-Q8_0'
def get_img2img_workflow_infotext_and_filename_flux2(image_filenames, prompt, seed, steps):
    if len(image_filenames) == 1:
        workflow_file = 'flux2-img2img.json'
    elif len(image_filenames) == 2:
        workflow_file = 'flux2-img2img-2-images.json'
    elif len(image_filenames) == 3:
        workflow_file = 'flux2-img2img-3-images.json'
    else:
        raise Exception("flux2 supports up to 3 images")
    if steps == 0:
        steps = 20
    steps = min(30, int(steps))
    workflow_file_path = Path(__file__).with_name(workflow_file)
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    comfy_workflow_object["25"]["inputs"]['noise_seed'] = seed
    comfy_workflow_object["48"]["inputs"]['steps'] = steps

    comfy_workflow_object["42"]["inputs"]['image'] = image_filenames[0]
    if len(image_filenames) > 1:
        comfy_workflow_object["46"]["inputs"]['image'] = image_filenames[1]
    if len(image_filenames) > 2:
        comfy_workflow_object["52"]["inputs"]['image'] = image_filenames[2]

    return comfy_workflow_object,  f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: flux2_dev_fp8'
def get_img2img_workflow_infotext_and_filename_qwen_image_edit_meitu(image_filenames, prompt, seed, steps):
    if not len(image_filenames) == 1:
        raise Exception("qwen_image_edit-MeiTu requires 1 image")
    workflow_file_path = Path(__file__).with_name("qwen-image-edit-meitu-img2img_lora-4-step.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["77"]["inputs"]['prompt'] = prompt
    comfy_workflow_object["99"]["inputs"]['seed'] = seed

    comfy_workflow_object["76"]["inputs"]['image'] = image_filenames[0]

    return comfy_workflow_object,  f'{prompt}\nSteps: 4 Seed: {seed}, Model: Qwen-Image-Edit-MeiTu, Lora: Qwen-Image-Lightning-4steps-V1.0'

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
