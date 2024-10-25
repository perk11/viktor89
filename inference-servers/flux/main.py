#Based on https://github.com/comfyanonymous/ComfyUI/blob/master/script_examples/websockets_api_example_ws_images.py
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

comfyui_server_address = "127.0.0.1:8188"
sem = threading.Semaphore()

def queue_prompt(prompt, client_id):
    p = {"prompt": prompt, "client_id": client_id}
    data = json.dumps(p).encode('utf-8')
    req = urllib.request.Request("http://{}/prompt".format(comfyui_server_address), data=data)
    return json.loads(urllib.request.urlopen(req).read())


def get_image(filename, subfolder, folder_type):
    data = {"filename": filename, "subfolder": subfolder, "type": folder_type}
    url_values = urllib.parse.urlencode(data)
    with urllib.request.urlopen("http://{}/view?{}".format(comfyui_server_address, url_values)) as response:
        return response.read()


def get_history(prompt_id):
    with urllib.request.urlopen("http://{}/history/{}".format(comfyui_server_address, prompt_id)) as response:
        return json.loads(response.read())


def get_images(prompt):
    client_id = str(uuid.uuid4())
    ws = websocket.WebSocket()
    ws.connect("ws://{}/ws?clientId={}".format(comfyui_server_address, client_id))
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
    print(data)

    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'flux1-dev.sft')
    steps = int(data.get('steps', 20))
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))

    workflow_file_path = Path(__file__).with_name("comfy_workflow.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()

    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["5"]["inputs"]["width"] = width
    comfy_workflow_object["5"]["inputs"]["height"] = height
    comfy_workflow_object["6"]["inputs"]["text"] = prompt
    comfy_workflow_object["12"]["inputs"]["unet_name"] = model
    comfy_workflow_object["17"]["inputs"]["steps"] = steps
    comfy_workflow_object["25"]["inputs"]["noise_seed"] = seed

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
    app.run(host='localhost', port=18094)
