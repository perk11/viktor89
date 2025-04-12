#Based on https://github.com/comfyanonymous/ComfyUI/blob/master/script_examples/websockets_api_example_ws_images.py
import argparse
import base64
import io
import json
import random
import threading
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

import websocket  # NOTE: websocket-client (https://github.com/websocket-client/websocket-client)
from flask import Flask, request, jsonify

app = Flask(__name__)
parser = argparse.ArgumentParser(description="Inference server for hidream using ComfyUI")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument('--comfy_ui_input_dir', type=str, help='Path to ComfyUI "input" directory', required=True)
args = parser.parse_args()

sem = threading.Semaphore()

def queue_prompt(prompt, client_id):
    p = {"prompt": prompt, "client_id": client_id}
    data = json.dumps(p).encode('utf-8')
    req = urllib.request.Request("http://{}/prompt".format(args.comfy_ui_server_address), data=data)
    return json.loads(urllib.request.urlopen(req).read())


def get_image(filename, subfolder, folder_type):
    data = {"filename": filename, "subfolder": subfolder, "type": folder_type}
    url_values = urllib.parse.urlencode(data)
    with urllib.request.urlopen("http://{}/view?{}".format(args.comfy_ui_server_address, url_values)) as response:
        return response.read()


def get_history(prompt_id):
    with urllib.request.urlopen("http://{}/history/{}".format(args.comfy_ui_server_address, prompt_id)) as response:
        return json.loads(response.read())


def get_images(prompt):
    client_id = str(uuid.uuid4())
    ws = websocket.WebSocket()
    ws.connect("ws://{}/ws?clientId={}".format(args.comfy_ui_server_address, client_id))
    prompt_id = queue_prompt(prompt, client_id)['prompt_id']
    output_images = {}
    current_node = ""
    while True:
        out = ws.recv()
        if isinstance(out, str):
            message = json.loads(out)
            if message['type'] == 'executing':
                data = message['data']
                if 'prompt_id' in data and data['prompt_id'] == prompt_id:
                    if data['node'] is None:
                        break  #Execution is done
                    else:
                        current_node = data['node']
        else:
            if current_node == 'save_image_websocket_node':
                images_output = output_images.get(current_node, [])
                images_output.append(out[8:])
                output_images[current_node] = images_output

    ws.close()
    return output_images



def get_txt2img_workflow(prompt,  negative_prompt, model, width, height, seed):
    workflow_file_path = Path(__file__).with_name("comfy_workflow_txt2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["19"]["inputs"]["width"] = width
    comfy_workflow_object["19"]["inputs"]["height"] = height
    comfy_workflow_object["19"]["inputs"]['primary_prompt'] = prompt
    comfy_workflow_object["19"]["inputs"]['negative_prompt'] = negative_prompt
    comfy_workflow_object["19"]["inputs"]['seed'] = seed
    comfy_workflow_object["19"]["inputs"]['model_type'] = model
    return comfy_workflow_object
def get_img2img_workflow(input_image_filename, prompt,  negative_prompt, model, seed, denoising_strength):
    workflow_file_path = Path(__file__).with_name("comfy_workflow_img2img.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["23"]["inputs"]['primary_prompt'] = prompt
    comfy_workflow_object["23"]["inputs"]['negative_prompt'] = negative_prompt
    comfy_workflow_object["23"]["inputs"]['seed'] = seed
    comfy_workflow_object["23"]["inputs"]['model_type'] = model
    comfy_workflow_object["23"]["inputs"]['denoising_strength'] = denoising_strength

    comfy_workflow_object["27"]["inputs"]['image'] = input_image_filename
    return comfy_workflow_object

@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data, flush=True)

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'dev-nf4')
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))

    comfy_workflow_object = get_txt2img_workflow(prompt, negative_prompt, model, width, height, seed)
    sem.acquire()

    try:
        images = get_images(comfy_workflow_object)
        print(f"{len(images)} images received from Comfy", flush=True)
    finally:
        sem.release()
    for node_id in images:
        for image_data in images[node_id]:
            image_base64 = base64.b64encode(io.BytesIO(image_data).getbuffer()).decode('utf-8')
            response = {
                'images': [image_base64],
                'parameters': {},
                'info': json.dumps({
                    'infotexts': [f'{prompt}\n, Seed: {seed}, Size: {width}x{height}, Model: HiDream-'
                                  + model
                                  ]
                })
            }

            return jsonify(response)

    return jsonify({
        'error': 'No images received from ComfyUI'
    })
@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt', "")
    denoising_strength = data.get('denoising_strength', 0.8)
    model = data.get('model', 'dev-nf4')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    init_images = data.get('init_images', [])

    data["init_images"]= "[omitted " + str(len(init_images)) + "]"
    print(data)
    images = []
    if len(init_images) != 1:
        return jsonify({'error': "A single init image is required"}), 400

    image_data = base64.b64decode(init_images[0])

    input_image_file_name = 'viktor89-hidream.jpg'
    with open(args.comfy_ui_input_dir + '/' + input_image_file_name, 'wb') as file:
        file.write(image_data)

    comfy_workflow_object = get_img2img_workflow(input_image_file_name, prompt, negative_prompt, model, seed, denoising_strength)

    try:
        images = get_images(comfy_workflow_object)
        print(f"{len(images)} images received from Comfy", flush=True)
    finally:
        sem.release()
    for node_id in images:
        for image_data in images[node_id]:
            image_base64 = base64.b64encode(io.BytesIO(image_data).getbuffer()).decode('utf-8')
            response = {
                'images': [image_base64],
                'parameters': {},
                'info': json.dumps({
                    'infotexts': [f'{prompt}\n Seed: {seed}, Model: HiDream-{model}, Denoising strength: {denoising_strength}']
                })
            }

            return jsonify(response)

    return jsonify({
        'error': 'No images received from ComfyUI'
    })

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
