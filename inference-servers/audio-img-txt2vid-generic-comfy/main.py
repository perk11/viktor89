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

# MiniMax H3 ref2vid exposes 3 image and 3 audio reference slots. Each slot is
# backed by a loader node (and a trim node per audio slot) plus a matching
# widget on the MiniMaxH3ReferenceToVideo node ("210"). Slots beyond the number
# actually supplied are dropped from the prompt at runtime — see
# disable_minimax_reference_nodes — so a request with fewer references never
# tries to load the placeholder files.
MINIMAX_REF2VID_PROMPT_NODE = "210"
MINIMAX_IMAGE_LOAD_NODES = ["216", "217", "218"]
MINIMAX_IMAGE_WIDGETS = [
    "ref_images.ref_image_0",
    "ref_images.ref_image_1",
    "ref_images.ref_image_2",
]
MINIMAX_AUDIO_LOAD_NODES = ["219", "220", "223"]
MINIMAX_AUDIO_TRIM_NODES = ["221", "222", "224"]
MINIMAX_AUDIO_WIDGETS = [
    "ref_audios.ref_audio_0",
    "ref_audios.ref_audio_1",
    "ref_audios.ref_audio_2",
]


@app.route('/audio_img_txt2vid', methods=['POST'])
def generate_video():
    return _handle_audio_video_request(allow_images=True)


@app.route('/audio_txt2vid', methods=['POST'])
def audio_txt2vid():
    return _handle_audio_video_request(allow_images=False)


def _handle_audio_video_request(allow_images):
    data = request.json
    print("Got new request", flush=True)
    init_images = data.get('init_images', [])
    init_audios = data.get('init_audios', [])
    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    model = data.get('model', 'ltx-2-distilled')
    if prompt is None:
        return jsonify({'error': "Prompt is required"}), 400

    if not allow_images:
        init_images = []

    # The MiniMax ref2vid workflow has exactly 3 image and 3 audio slots; LTX
    # consumes a single audio and at most one image.
    match model:
        case 'ltx-2-distilled':
            if len(init_audios) != 1:
                return jsonify({'error': "Exactly one init audio is required for ltx-2-distilled."}), 400
            if len(init_images) > 1:
                return jsonify({'error': "At most one init image is supported for ltx-2-distilled."}), 400
        case 'minimax-h3-ref2vid':
            if len(init_images) > 3:
                return jsonify({'error': "At most 3 init images are supported."}), 400
            if len(init_audios) > 3:
                return jsonify({'error': "At most 3 init audios are supported."}), 400
        case _:
            return jsonify({"error": "Unknown model: " + model}), 400

    if model not in semaphores:
        semaphores[model] = threading.Semaphore()

    print("Acquiring lock for " + model, flush=True)
    semaphores[model].acquire()
    print("Acquired lock for " + model, flush=True)
    try:
        match model:
            case 'ltx-2-distilled':
                audio_file_name = _write_audio_file(model, init_audios[0], 0)
                image_file_name = None
                if len(init_images) == 1:
                    image_file_name = _write_image_file(model, init_images[0], 0)
                comfy_workflow_object, infotext = get_workflow_and_infotext_ltx2_distilled(
                    audio_file_name, image_file_name, prompt, seed)
            case 'minimax-h3-ref2vid':
                image_file_names = [
                    _write_image_file(model, image_b64, i) for i, image_b64 in enumerate(init_images)
                ]
                audio_file_names = [
                    _write_audio_file(model, audio_b64, i) for i, audio_b64 in enumerate(init_audios)
                ]
                comfy_workflow_object, infotext = get_workflow_and_infotext_minimax_h3_ref2vid(
                    image_file_names, audio_file_names, prompt, seed, _resolve_duration_seconds(data))
        return comfy_workflow_vhs_video_combine_to_json_video_response(
            comfy_workflow_object, args.comfy_ui_server_address, infotext)
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500
    finally:
        semaphores[model].release()


def _resolve_duration_seconds(data):
    # An explicit duration wins; otherwise derive it from the /frames-derived
    # num_frames the PHP client sends (MiniMax-H3 renders at 24 fps).
    duration = data.get('duration')
    if duration is None and data.get('num_frames') is not None:
        duration = int(data['num_frames']) / 24
    return duration


def _write_audio_file(model, audio_b64, index):
    audio_data = base64.b64decode(audio_b64)
    audio_file_name = f"viktor89-{model}-audio-{index}.ogg"
    with open(args.comfy_ui_input_dir + '/' + audio_file_name, 'wb') as audio_file:
        audio_file.write(audio_data)
    return audio_file_name


def _write_image_file(model, image_b64, index):
    image_data = base64.b64decode(image_b64)
    image_file_name = f"viktor89-{model}-image-{index}.jpg"
    with open(args.comfy_ui_input_dir + '/' + image_file_name, 'wb') as image_file:
        image_file.write(image_data)
    return image_file_name


def get_workflow_and_infotext_ltx2_distilled(audio_file_name, image_file_name, prompt, seed):
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


def get_workflow_and_infotext_minimax_h3_ref2vid(image_file_names, audio_file_names, prompt, seed, duration_seconds=None):
    workflow_file_path = Path(__file__).with_name("minimax_h3_ref2vid_images_audio.json")
    with workflow_file_path.open('r') as workflow_file:
        comfy_workflow = workflow_file.read()
    comfy_workflow_object = json.loads(comfy_workflow)

    comfy_workflow_object["210"]["inputs"]["prompt"] = prompt
    comfy_workflow_object["209"]["inputs"]["noise_seed"] = seed
    if duration_seconds is not None:
        # Node 201 drives the video length in seconds; node 202 snaps it to a
        # valid frame count. Clamp to the same 5–15s range the reference-audio
        # trim and the other MiniMax-H3 workflows use.
        comfy_workflow_object["201"]["inputs"]["value"] = max(5, min(15, float(duration_seconds)))

    _wire_minimax_reference_images(comfy_workflow_object, image_file_names)
    _wire_minimax_reference_audios(comfy_workflow_object, audio_file_names)

    ref_summary_parts = []
    if image_file_names:
        ref_summary_parts.append(f"{len(image_file_names)} image(s)")
    if audio_file_names:
        ref_summary_parts.append(f"{len(audio_file_names)} audio(s)")
    ref_summary = ", ".join(ref_summary_parts) if ref_summary_parts else "no references"

    return comfy_workflow_object, f'{prompt}\nSeed: {seed}, Model: minimax-h3-ref2vid, References: {ref_summary}'


def _wire_minimax_reference_images(workflow, image_file_names):
    for index, file_name in enumerate(image_file_names):
        workflow[MINIMAX_IMAGE_LOAD_NODES[index]]["inputs"]["image"] = file_name
    _disable_minimax_reference_nodes(
        workflow,
        len(image_file_names),
        MINIMAX_IMAGE_LOAD_NODES,
        [],
        MINIMAX_IMAGE_WIDGETS,
    )


def _wire_minimax_reference_audios(workflow, audio_file_names):
    for index, file_name in enumerate(audio_file_names):
        workflow[MINIMAX_AUDIO_LOAD_NODES[index]]["inputs"]["audio"] = file_name
    _disable_minimax_reference_nodes(
        workflow,
        len(audio_file_names),
        MINIMAX_AUDIO_LOAD_NODES,
        MINIMAX_AUDIO_TRIM_NODES,
        MINIMAX_AUDIO_WIDGETS,
    )


def _disable_minimax_reference_nodes(workflow, provided_count, load_nodes, extra_nodes, widgets):
    # Remove the loader nodes (and any dependent nodes, e.g. TrimAudioDuration)
    # for slots that were not supplied, together with the corresponding widget
    # on the MiniMaxH3ReferenceToVideo node. Leaving a loader in place would
    # make ComfyUI try to load the placeholder "example.png"/"example.ogg", and
    # leaving the widget would point it at a deleted node.
    for index in range(provided_count, len(load_nodes)):
        workflow.pop(load_nodes[index], None)
        if index < len(extra_nodes):
            workflow.pop(extra_nodes[index], None)
        workflow[MINIMAX_REF2VID_PROMPT_NODE]["inputs"].pop(widgets[index], None)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
