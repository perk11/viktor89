#Based on https://github.com/comfyanonymous/ComfyUI/blob/master/script_examples/websockets_api_example_ws_images.py
import argparse
import base64
import io
import json
import os
import random
import threading
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

import websocket  # NOTE: websocket-client (https://github.com/websocket-client/websocket-client)
from flask import Flask, request, jsonify

app = Flask(__name__)
parser = argparse.ArgumentParser(description="Inference server for flux using ComfyUI")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
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



@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data, flush=True)

    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'lumina_2.safetensors')
    steps = int(data.get('steps', 36))
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))

    workflow_file_path = Path(__file__).with_name("comfy_workflow.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()

    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["13"]["inputs"]["width"] = width
    comfy_workflow_object["13"]["inputs"]["height"] = height
    comfy_workflow_object["6"]["inputs"]["text"] = "You are an assistant designed to generate superior images with the superior degree of image-text alignment based on textual prompts or user prompts. <Prompt Start> " + prompt
    comfy_workflow_object["4"]["inputs"]["ckpt_name"] = model
    comfy_workflow_object["3"]["inputs"]["steps"] = steps
    comfy_workflow_object["3"]["inputs"]["seed"] = seed

    sem.acquire()

    try:
        images = get_images(comfy_workflow_object)
    finally:
        sem.release()
    for node_id in images:
        for image_data in images[node_id]:
            image_base64 = base64.b64encode(io.BytesIO(image_data).getbuffer()).decode('utf-8')
            response = {
                'images': [image_base64],
                'parameters': {},
                'info': json.dumps({
                    'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: '
                                  + os.path.splitext(model)[0]
                                  ]
                })
            }

            return jsonify(response)

    return jsonify({
        'error': 'No images received from ComfyUI'
    })

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
