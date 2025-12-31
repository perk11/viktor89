import argparse
import base64
import json
import random
import threading
from io import BytesIO

import torch
from diffusers import DiffusionPipeline
from flask import Flask, request, jsonify

app = Flask(__name__)



parser = argparse.ArgumentParser(description="Inference server for Qwen-Image-2512")
parser.add_argument('--port', type=int, help='port to listen on')
args = parser.parse_args()
model_name = "Qwen/Qwen-Image-2512"
generator = torch.Generator()
sem = threading.Semaphore()
torch_dtype = torch.bfloat16
device = "cuda"

pipe = DiffusionPipeline.from_pretrained(model_name, torch_dtype=torch_dtype).to(device)
ASPECT_RATIOS = {
    "1:1": (1328, 1328),
    "16:9": (1664, 928),
    "9:16": (928, 1664),
    "4:3": (1472, 1104),
    "3:4": (1104, 1472),
    "3:2": (1584, 1056),
    "2:3": (1056, 1584),
}


def _orientation(width: int, height: int) -> str:
    if width == height:
        return "square"
    return "landscape" if width > height else "portrait"


def choose_closest_allowed_size(width: int, height: int) -> tuple[str, int, int, bool]:
    input_orientation = _orientation(width, height)

    if any(width <= allowed_w and height <= allowed_h for allowed_w, allowed_h in ASPECT_RATIOS.values()):
        return "input", width, height, False

    candidate_items = [
        (label, allowed_w, allowed_h)
        for label, (allowed_w, allowed_h) in ASPECT_RATIOS.items()
        if _orientation(allowed_w, allowed_h) == input_orientation
    ]

    if not candidate_items:
        candidate_items = [(label, allowed_w, allowed_h) for label, (allowed_w, allowed_h) in ASPECT_RATIOS.items()]

    best_label = ""
    best_width = 0
    best_height = 0
    best_distance = None

    for label, allowed_w, allowed_h in candidate_items:
        distance = (allowed_w - width) ** 2 + (allowed_h - height) ** 2
        if best_distance is None or distance < best_distance:
            best_distance = distance
            best_label = label
            best_width = allowed_w
            best_height = allowed_h

    return best_label, best_width, best_height, True


@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data, flush=True)

    prompt = data.get('prompt')
    negative_prompt = data.get(
        'negative_prompt',
        '低分辨率，低画质，肢体畸形，手指畸形，画面过饱和，蜡像感，人脸无细节，过度光滑，画面具有AI感。构图混乱。文字模糊，扭曲。'
    )
    seed = int(data.get('seed', random.randint(1, 2 ** 32 - 1)))
    width = int(data.get('width', 1328))
    height = int(data.get('height', 1328))
    steps = int(data.get('steps', 25))

    matched_label, matched_width, matched_height, changed = choose_closest_allowed_size(width, height)
    if changed:
        print(
            f"Requested {width}x{height} exceeds allowed sizes; using {matched_label} -> {matched_width}x{matched_height}",
            flush=True
        )
    width, height = matched_width, matched_height

    return infer_image_to_json(prompt, negative_prompt, seed, steps, width, height)


def infer_image_to_json(prompt, negative_prompt, seed, steps, width, height):
    sem.acquire()
    try:
        image = pipe(
            prompt=prompt,
            negative_prompt=negative_prompt,
            width=width,
            height=height,
            num_inference_steps=steps,
            true_cfg_scale=4.0,
            generator=generator.manual_seed(seed)
        ).images[0]
    except Exception as e:
        print(e, flush=True)
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
        torch.cuda.empty_cache()
        torch.cuda.ipc_collect()
    buffered = BytesIO()
    image.save(buffered, format="PNG")
    image_base64 = base64.b64encode(buffered.getvalue()).decode('utf-8')
    response = {
        'images': [image_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [
                f'{prompt}\nNegative prompt: {negative_prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: Qwen-Image-2512'
            ]
        })
    }
    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
