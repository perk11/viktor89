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
#Allow relative imports
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


semaphores = {}
@app.route('/vid_txt2vid', methods=['POST'])
def generate_video():
    data = request.json
    data_for_print = data.copy()
    data_for_print['init_videos'] = len(data['init_videos'])
    print("Got new request" + json.dumps(data_for_print), flush=True)
    init_videos = data.get('init_videos', None)
    if init_videos is None:
        return jsonify({'error': '"s" parameter is required'}), 400

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt', None)
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'ditto')
    num_frames = int(data.get('num_frames', 73))

    if model not in semaphores:
        semaphores[model] = threading.Semaphore()

    print("Acquiring lock for " + model, flush=True)
    semaphores[model].acquire()
    print("Acquired lock for " + model, flush=True)
    video_filenames = []
    try:
        for index, video in enumerate(init_videos):
            video_data = base64.b64decode(video)
            file_name = "viktor89-" + model + '-video-' +  str(index) + '.mp4'
            video_filenames.append(file_name)
            with open(args.comfy_ui_input_dir + '/' + file_name, 'wb') as video_file:
                video_file.write(video_data)

            match model:
                case 'EDitto':
                    vhs = False
                    comfy_workflow_object, infotext = get_workflow_and_infotext_editto(video_filenames[0], prompt, negative_prompt, seed, num_frames)
                case 'LTX-2-extend':
                    vhs = True
                    comfy_workflow_object, infotext = get_workflow_and_infotext_ltx_2_extend(video_filenames[0], prompt, seed, num_frames)
                case _:
                    return jsonify({"error": "Unknown model: " + model}), 400
            with open("/tmp/workflow.json", "w") as workflow:
                workflow.write(json.dumps(comfy_workflow_object))
            if vhs:
                return comfy_workflow_vhs_video_combine_to_json_video_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
            else:
                return comfy_workflow_to_json_video_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500
    finally:
        semaphores[model].release()


def get_workflow_and_infotext_editto(video_filename, prompt, negative_prompt, seed, num_frames):
    workflow_file_path = Path(__file__).with_name("editto.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["30"]["inputs"]["positive_prompt"] = prompt
    if negative_prompt is not None:
        comfy_workflow_object["30"]["inputs"]["negative_prompt"] = negative_prompt
    comfy_workflow_object["16"]["inputs"]["video"] = video_filename
    comfy_workflow_object["16"]["inputs"]["frame_load_cap"] = num_frames
    comfy_workflow_object["35"]["inputs"]["seed"] = seed

    return comfy_workflow_object, f'{prompt}\nSeed: {seed}, Model: EDitto'

def get_workflow_and_infotext_ltx_2_extend(video_filename, prompt, seed, num_frames):
    workflow_file_path = Path(__file__).with_name("ltx2-extend.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    num_frames = max(num_frames, 121)
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["5175"]["inputs"]["value"] = prompt

    comfy_workflow_object["5216"]["inputs"]["video"] = video_filename
    comfy_workflow_object["5224"]["inputs"]["video"] = video_filename
    comfy_workflow_object["5186"]["inputs"]["value"] = num_frames
    comfy_workflow_object["5189:5097"]["inputs"]["noise_seed"] = seed

    return comfy_workflow_object, f'{prompt}\nSeed: {seed}, Model: ltx-2-19b-dev-fp8'
if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
