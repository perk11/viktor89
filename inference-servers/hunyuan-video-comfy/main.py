# Based on https://github.com/comfyanonymous/ComfyUI/blob/master/script_examples/websockets_api_example_ws_images.py
import argparse
import base64
import io
import json
import os
import random
import subprocess
import tempfile
import threading
import traceback
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

import websocket  # NOTE: websocket-client (https://github.com/websocket-client/websocket-client)
from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="Inference server for Hunyuan-Video based on ComfyUI.")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
args = parser.parse_args()
app = Flask(__name__)

comfyui_server_address = args.comfy_ui_server_address
print(f"ComfyUI server address: {comfyui_server_address}")
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


def images_to_video(images):
    if len(images) == 0:
        raise Exception("No images received")
    with tempfile.TemporaryDirectory() as tmpdir:
        frame_paths = []
        for i, frame in enumerate(images):
            frame_path = os.path.join(tmpdir, f"frame_{i:04d}.png")
            with open(frame_path, 'wb') as file:
                file.write(frame)  # Write the string to the file
            frame_paths.append(frame_path)

        frame_pattern = os.path.join(tmpdir, "frame_%04d.png")

        with tempfile.NamedTemporaryFile(suffix='.mp4', delete=False) as tmp_video_file:
            video_file_path = tmp_video_file.name
        ffmpeg_cmd = [
            'ffmpeg',
            '-y',
            '-r', '24',
            '-i', frame_pattern,
            '-vcodec', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-preset', 'veryslow',
            '-crf', '22',
            video_file_path
        ]
        try:
            process = subprocess.Popen(ffmpeg_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            stdout, stderr = process.communicate()

            if process.returncode != 0:
                print(f"ffmpeg error: {stderr.decode()}")
                raise Exception("ffmpeg failed")
            with open(video_file_path, 'rb') as f:
                return f.read()
        finally:
            os.remove(video_file_path)


def get_images(prompt):
    client_id = str(uuid.uuid4())
    ws = websocket.WebSocket()
    print("Connecting to {}".format(comfyui_server_address))
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
                        break  # Execution is done
                    else:
                        current_node = data['node']
        else:
            if current_node == 'save_image_websocket_node':
                images_output = output_images.get(current_node, [])
                images_output.append(out[8:])
                output_images[current_node] = images_output

    ws.close()
    return output_images['save_image_websocket_node']



@app.route('/txt2vid', methods=['POST'])
def generate_video():
    data = request.json
    print(data)

    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'fast-hunyuan-video-t2v-720p-Q4_K_M')
    steps = int(data.get('steps', 35))
    width = int(data.get('width', 720))
    height = int(data.get('height', 480))
    num_frames = int(data.get('num_frames', 97))
    guidance = int(data.get('guidance', 7))
    loras = data.get('loras', [])

    if len(loras) > 3:
        return jsonify({'error': 'Up to 3 Loras supported'}), 500

    comfy_workflow_object = get_workflow(guidance, height, model, num_frames, prompt, seed, steps, width)
    loras_list=""
    for index, lora in enumerate(loras):
        if not "weight" in lora:
            print(lora)
            return jsonify({'error': 'Missing Lora weight attribute', lora: lora}), 500
        if not "name" in lora:
            print(lora)
            return jsonify({'error': 'Missing Lora name attribute', lora: lora}), 500
        comfy_workflow_object["93"]["inputs"]["lora"+str(index+1)] = lora["name"]
        comfy_workflow_object["93"]["inputs"]["lora"+str(index+1)+"_weight"] = lora["weight"]
        if index > 1:
            loras_list += ","
        else:
            loras_list = " LORAs: "
        loras_list += lora["name"] + ":"+ str(lora["weight"])
    sem.acquire()
    try:
        images = get_images(comfy_workflow_object)
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
    try:
        video_data = images_to_video(images)
        video_contents_base64 = base64.b64encode(video_data).decode('utf-8')
        response = {
            'videos': [video_contents_base64],
            'parameters': {
                'width': width,
                'height': height,
                'num_frames': num_frames,
                'seed': seed,
                'num_inference_steps': steps,
            },
            'info': json.dumps({
                'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Frames: {num_frames}, Model: ' + model+loras_list]
            })
        }
        return jsonify(response)

    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500


def get_workflow(guidance, height, model, num_frames, prompt, seed, steps, width):
    workflow_file_path = Path(__file__).with_name("comfy_workflow.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["44"]["inputs"]["text"] = prompt
    comfy_workflow_object["99"]["inputs"]["unet_name"] = model + '.gguf'
    comfy_workflow_object["45"]["inputs"]["width"] = width
    comfy_workflow_object["45"]["inputs"]["height"] = height
    comfy_workflow_object["17"]["inputs"]["steps"] = steps
    comfy_workflow_object["25"]["inputs"]["noise_seed"] = seed
    comfy_workflow_object["45"]["inputs"]["length"] = num_frames
    comfy_workflow_object["26"]["inputs"]["guidance"] = guidance
    return comfy_workflow_object


@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_single_frame():
    data = request.json
    print(data)

    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'fast-hunyuan-video-t2v-720p-Q4_K_M')
    steps = int(data.get('steps', 35))
    width = int(data.get('width', 544))
    height = int(data.get('height', 960))
    num_frames = 1
    guidance = int(data.get('guidance', 7))
    loras = data.get('loras', [])

    if len(loras) > 3:
        return jsonify({'error': 'Up to 3 Loras supported'}), 500
    comfy_workflow_object = get_workflow(guidance, height, model, num_frames, prompt, seed, steps, width)
    loras_list=""
    for index, lora in enumerate(loras):
        if not "weight" in lora:
            print(lora)
            return jsonify({'error': 'Missing Lora weight attribute', lora: lora}), 500
        if not "name" in lora:
            print(lora)
            return jsonify({'error': 'Missing Lora name attribute', lora: lora}), 500
        comfy_workflow_object["93"]["inputs"]["lora"+str(index+1)] = lora["name"]
        comfy_workflow_object["93"]["inputs"]["lora"+str(index+1)+"_weight"] = lora["weight"]
        if index > 1:
            loras_list += ","
        else:
            loras_list = " LORAs: "
        loras_list += lora["name"] + ":"+ str(lora["weight"])
    sem.acquire()
    try:
        images = get_images(comfy_workflow_object)
    finally:
        sem.release()
    if len(images) != 1:
        return jsonify({
            'error': 'Unexpected number of images received from ComfyUI: ' + str(len(images))
        })
    image_base64 = base64.b64encode(io.BytesIO(images[0]).getbuffer()).decode('utf-8')
    response = {
        'images': [image_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: '
                          + model+loras_list
                          ]
        })
    }

    return jsonify(response)



if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
