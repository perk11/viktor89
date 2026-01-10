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

def get_txt2img_workflow_and_infotext_wan22(model, prompt, negative_prompt, seed, steps, width, height):
    workflow_file_path = Path(__file__).with_name("wan22_tx2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    comfy_workflow_object["7"]["inputs"]['text'] = negative_prompt
    if steps > 1:
        high_to_low_transition = int(steps/2)
        comfy_workflow_object["81"]["inputs"]['value'] = high_to_low_transition
        comfy_workflow_object["80"]["inputs"]['value'] = steps
    else:
        high_to_low_transition = comfy_workflow_object["81"]["inputs"]['value']
        steps = comfy_workflow_object["80"]["inputs"]['value']
    comfy_workflow_object["57"]["inputs"]['noise_seed'] = seed
    comfy_workflow_object["77"]["inputs"]['width'] = width
    comfy_workflow_object["77"]["inputs"]['height'] = height
    return comfy_workflow_object, f'{prompt}\nLow noise steps: {steps-high_to_low_transition}, High noise steps: {high_to_low_transition}, Seed: {seed}, Size: {width}x{height}, Model: ' + model
def get_txt2img_workflow_and_infotext_flux2(model, prompt, seed, steps, width, height):
    workflow_file_path = Path(__file__).with_name("flux2-txt2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    if steps <= 0:
        steps = 20
    comfy_workflow_object["48"]["inputs"]['steps'] = steps
    comfy_workflow_object["25"]["inputs"]['noise_seed'] = seed

    comfy_workflow_object["47"]["inputs"]['width'] = width
    comfy_workflow_object["48"]["inputs"]['width'] = width
    comfy_workflow_object["47"]["inputs"]['height'] = height
    comfy_workflow_object["48"]["inputs"]['height'] = height

    return comfy_workflow_object, f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: ' + model
def get_txt2img_workflow_and_infotext_flux2_turbo(prompt, seed, width, height):
    workflow_file_path = Path(__file__).with_name("flux2-turbo-txt2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    comfy_workflow_object["25"]["inputs"]['noise_seed'] = seed

    comfy_workflow_object["47"]["inputs"]['width'] = width
    comfy_workflow_object["48"]["inputs"]['width'] = width
    comfy_workflow_object["47"]["inputs"]['height'] = height
    comfy_workflow_object["48"]["inputs"]['height'] = height

    return comfy_workflow_object, f'{prompt}\nSteps: 8, Seed: {seed}, Size: {width}x{height}, Model:  flux2_dev_fp8, Lora: Flux_2-Turbo-LoRA_comfyui'
def get_txt2img_workflow_and_infotext_z_image(model, loras, prompt, negative_prompt, seed, steps, width, height):
    if len(loras) == 0:
        workflow_file_path = Path(__file__).with_name("z-image-turbo-txt2img.json")
    elif len(loras) == 1:
        workflow_file_path = Path(__file__).with_name("z-image-turbo-lora-txt2img.json")
    else:
        raise ValueError('Only one Lora supported by z-image')
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    if negative_prompt is None:
        negative_prompt = comfy_workflow_object["7"]["inputs"]['text']
    else:
        comfy_workflow_object["7"]["inputs"]['text'] = negative_prompt
    if steps > 0:
        comfy_workflow_object["3"]["inputs"]['steps'] = steps
    else:
        steps = comfy_workflow_object["3"]["inputs"]['steps']
    comfy_workflow_object["3"]["inputs"]['seed'] = seed
    comfy_workflow_object["13"]["inputs"]['width'] = width
    comfy_workflow_object["13"]["inputs"]['height'] = height
    infotext =  f'{prompt}\nNegative prompt: {negative_prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: ' + model
    if len(loras) == 1:
        comfy_workflow_object["19"]["inputs"]["lora_name"] = loras[0]['name']
        comfy_workflow_object["19"]["inputs"]["strength_model"] = loras[0]['weight']
        infotext += f' Lora: {comfy_workflow_object["19"]["inputs"]["lora_name"]}:{comfy_workflow_object["19"]["inputs"]["strength_model"]}'


    return comfy_workflow_object, infotext

@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data, flush=True)

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'flux2_dev_fp8')
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))
    steps = int(data.get('steps', 0))
    loras = data.get('loras', [])
    for index, lora in enumerate(loras):
        if not "weight" in lora:
            print(lora)
            return jsonify({'error': 'Missing Lora weight attribute', lora: lora}), 500
        if not "name" in lora:
            print(lora)
            return jsonify({'error': 'Missing Lora name attribute', lora: lora}), 500
    match model:
        case 'chroma-unlocked-v31' | 'chroma_v41LowStepRl':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_chroma(model, prompt, negative_prompt, seed, steps, width, height)
        case 'qwen_image_fp8_e4m3fn':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_qwen(model, prompt, negative_prompt, seed, steps, width, height)
        case 'wan2.2_t2v_fp8':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_wan22(model, prompt, negative_prompt, seed, steps, width, height)
        case 'flux2_dev_fp8':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_flux2(model, prompt, seed, steps, width, height)
        case 'flux2_dev_fp8-turbo-8-steps':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_flux2_turbo(prompt, seed, width, height)
        case 'z_image_turbo':
            comfy_workflow_object, infotext = get_txt2img_workflow_and_infotext_z_image(model, loras, prompt, negative_prompt, seed, steps, width, height)
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

def get_img2img_workflow_infotext_and_filename_qwen_image_edit(prompt, seed, steps):
    if steps == 0:
        steps = 20
    input_image_filename = 'viktor89-qwen-image-edit.jpg'
    workflow_file_path = Path(__file__).with_name("qwen-image-img2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["121"]["inputs"]['prompt'] = prompt
    comfy_workflow_object["25"]["inputs"]['noise_seed'] = seed
    comfy_workflow_object["17"]["inputs"]['steps'] = steps

    comfy_workflow_object["127"]["inputs"]['image'] = input_image_filename
    return comfy_workflow_object,  f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: Qwen_Image_Edit-Q8_0', input_image_filename

def get_img2img_workflow_infotext_and_filename_qwen_image_edit_lora(prompt, seed, steps, lora):
    if steps == 0:
        steps = 8
    input_image_filename = 'viktor89-qwen-image-edit-lora.jpg'
    workflow_file_path = Path(__file__).with_name("qwen-image-img2img_lora.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["121"]["inputs"]['prompt'] = prompt
    comfy_workflow_object["25"]["inputs"]['noise_seed'] = seed
    comfy_workflow_object["17"]["inputs"]['steps'] = steps
    comfy_workflow_object["133"]["inputs"]['lora_name'] = lora + ".safetensors"

    comfy_workflow_object["127"]["inputs"]['image'] = input_image_filename
    return comfy_workflow_object,  f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: Qwen_Image_Edit-Q8_0, Lora: ' + lora, input_image_filename
def get_img2img_workflow_and_infotext_z_image(model,  prompt, negative_prompt, seed):
    input_image_filename = 'viktor89-z_image.jpg'
    workflow_file_path = Path(__file__).with_name("z-image-turbo-img2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    if negative_prompt is None:
        negative_prompt = "blurry ugly bad"
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    comfy_workflow_object["7"]["inputs"]['text'] = negative_prompt
    comfy_workflow_object["28"]["inputs"]['noise_seed'] = seed

    comfy_workflow_object["38"]["inputs"]['image'] = input_image_filename

    return comfy_workflow_object,  f'{prompt}\nNegative prompt: {negative_prompt}\n Seed: {seed}, Model: z-image-turbo', input_image_filename

sem = threading.Semaphore() #Todo use a dict with semaphores based on input image file name
@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    model = data.get('model', '(blank)')
    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt')
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
        case 'Qwen_Image_Edit-Q8_0':
            comfy_workflow_object, infotext, input_image_file_name = get_img2img_workflow_infotext_and_filename_qwen_image_edit(prompt, seed, steps)
        case 'Qwen_Image_Edit-Q8_0-lora':
            lora = data.get('lora', '')
            if lora == '':
                return jsonify({'error': "Lora is required"}), 400
            comfy_workflow_object, infotext, input_image_file_name = get_img2img_workflow_infotext_and_filename_qwen_image_edit_lora(prompt, seed, steps, lora)
        case 'z_image_turbo':
            comfy_workflow_object, infotext, input_image_file_name = get_img2img_workflow_and_infotext_z_image(model,  prompt, negative_prompt, seed)
        case _:
            return jsonify({"error": "Unknown model: " + model}), 400
    sem.acquire()
    try:
        with open(args.comfy_ui_input_dir + '/' + input_image_file_name, 'wb') as file:
            file.write(image_data)
        return comfy_workflow_to_json_image_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    finally:
        sem.release()


@app.route('/sdapi/v1/options')
def get_options():
    return {
        "sd_model_checkpoint": "flux2_dev_fp8"
    }

@app.route('/sdapi/v1/sd-models')
def get_models():
    return jsonify([
        {
            "title": "flux2_dev_fp8",
            "model_name": "flux2_dev_fp8",
        }
    ])

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
