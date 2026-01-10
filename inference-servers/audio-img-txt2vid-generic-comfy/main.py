import argparse
import base64
import json
import random
import sys
import threading
import traceback
from pathlib import Path

from flask import Flask, request, jsonify
#Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))

from util.comfy import comfy_workflow_vhs_video_combine_to_json_video_response


parser = argparse.ArgumentParser(description="Inference server based on ComfyUI.")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--comfy_ui_server_address', type=str, help='address where Comfy UI is listening', required=True)
parser.add_argument('--comfy_ui_input_dir', type=str, help='Path to ComfyUI "input" directory', required=True)
args = parser.parse_args()
app = Flask(__name__)

comfyui_server_address = args.comfy_ui_server_address
print(f"ComfyUI server address: {comfyui_server_address}")

comfyui_input_dir = args.comfy_ui_input_dir


semaphores = {}
@app.route('/audio_img_txt2vid', methods=['POST'])
def generate_video():
    data = request.json
    print("Got new request", flush=True)
    init_images = data.get('init_images', [])
    init_audios = data.get('init_audios', [])
    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'ltx-2-distilled')
    if prompt is None:
        return jsonify({'error': "Prompt is required"}), 400

    if len(init_images) != 1:
        return jsonify({'error': "One init images is required."}), 400
    if len(init_audios) != 1:
        return jsonify({'error': "One init audio is required."}), 400

    if model not in semaphores:
        semaphores[model] = threading.Semaphore()

    print("Acquiring lock for " + model, flush=True)
    semaphores[model].acquire()
    print("Acquired lock for " + model, flush=True)
    try:
        audio_data = base64.b64decode(init_audios[0])
        audio_file_name = "viktor89-" + model + '-audio.ogg'
        with open(args.comfy_ui_input_dir + '/' + audio_file_name, 'wb') as audio_file:
            audio_file.write(audio_data)
        image_data = base64.b64decode(init_images[0])
        image_file_name = "viktor89-" + model + '-image.jpg'
        with open(args.comfy_ui_input_dir + '/' + image_file_name, 'wb') as image_file:
            image_file.write(image_data)

        match model:
            case 'ltx-2-distilled':
                comfy_workflow_object, infotext = get_workflow_and_infotext_ltx2_distilled(audio_file_name, image_file_name, prompt, seed)
            case _:
                return jsonify({"error": "Unknown model: " + model}), 400
        return comfy_workflow_vhs_video_combine_to_json_video_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500
    finally:
        semaphores[model].release()


def  get_workflow_and_infotext_ltx2_distilled(audio_file_name, image_file_name, prompt, seed):
    if image_file_name is None:
        workflow_file_path = Path(__file__).with_name("ltx-2-distilled-audio-txt2vid.json")
    else:
        workflow_file_path = Path(__file__).with_name("ltx-2-distilled-audio-img-txt2vid.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)
    comfy_workflow_object["169"]["inputs"]["text"] = prompt
    if image_file_name is not None:
        comfy_workflow_object["240"]["inputs"]["image"] = image_file_name
    comfy_workflow_object["243"]["inputs"]["audio"] = audio_file_name
    comfy_workflow_object["178"]["inputs"]["seed"] = seed

    return comfy_workflow_object, f'{prompt}\nSeed: {seed}, Model: ltx-2-19b-distilled'

@app.route('/audio_txt2vid', methods=['POST'])
def audio_txt2vid():
    data = request.json
    print("Got new request", flush=True)
    init_audios = data.get('init_audios', [])
    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'ltx-2-distilled')
    if prompt is None:
        return jsonify({'error': "Prompt is required"}), 400

    if len(init_audios) != 1:
        return jsonify({'error': "One init audio is required."}), 400

    if model not in semaphores:
        semaphores[model] = threading.Semaphore()

    print("Acquiring lock for " + model, flush=True)
    semaphores[model].acquire()
    print("Acquired lock for " + model, flush=True)
    try:
        audio_data = base64.b64decode(init_audios[0])
        audio_file_name = "viktor89-" + model + '-audio.ogg'
        with open(args.comfy_ui_input_dir + '/' + audio_file_name, 'wb') as audio_file:
            audio_file.write(audio_data)

        match model:
            case 'ltx-2-distilled':
                comfy_workflow_object, infotext = get_workflow_and_infotext_ltx2_distilled(audio_file_name, None, prompt, seed)
            case _:
                return jsonify({"error": "Unknown model: " + model}), 400
        return comfy_workflow_vhs_video_combine_to_json_video_response(comfy_workflow_object, args.comfy_ui_server_address, infotext)
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500
    finally:
        semaphores[model].release()

if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
