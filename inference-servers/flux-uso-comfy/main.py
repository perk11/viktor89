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

def get_img2img_workflow_infotext(prompt, seed, steps):
    if steps == 0:
        steps = 20
    workflow_file_path = Path(__file__).with_name("flux1_dev_uso_reference_image_gen.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["6"]["inputs"]['text'] = prompt
    comfy_workflow_object["31"]["inputs"]['seed'] = seed
    comfy_workflow_object["31"]["inputs"]['steps'] = steps

    return comfy_workflow_object,  f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: FLUX.1-USO'


sem = threading.Semaphore()
@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    prompt = data.get('prompt')
    steps = data.get('steps', 0)
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    init_images = data.get('init_images', [])

    data["init_images"]= "[omitted " + str(len(init_images)) + "]"
    print(data)
    images = []
    if len(init_images) != 2:
        return jsonify({'error': "Two init images are required. Image one will be used for subject, image two for style reference"}), 400

    comfy_workflow_object, infotext = get_img2img_workflow_infotext(prompt, seed, steps)

    sem.acquire()
    try:
        with open(args.comfy_ui_input_dir + '/flux_uso_subject.png', 'wb') as file1:
            file1.write(base64.b64decode(init_images[0]))
        with open(args.comfy_ui_input_dir + '/flux_uso_style_reference.png', 'wb') as file2:
            file2.write(base64.b64decode(init_images[1]))
        return comfy_workflow_to_json_image_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    finally:
        sem.release()

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
