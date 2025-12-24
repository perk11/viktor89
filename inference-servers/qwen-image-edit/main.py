import argparse
import base64
import io
import os
import random
import sys
import threading
from pathlib import Path

import torch
from PIL import Image
os.environ["HF_HUB_OFFLINE"] = "1"
os.environ["TRANSFORMERS_OFFLINE"] = "1"
os.environ["DIFFUSERS_OFFLINE"] = "1"
from diffusers import QwenImageEditPlusPipeline
from flask import Flask, request, jsonify

# Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))
from util.image_to_json import image_to_json_response

app = Flask(__name__)
parser = argparse.ArgumentParser(description="qwen-image-edit workflow")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--model_dir', type=str, help='dir containing model files', required=True)
parser.add_argument('--lora', type=str, help='LORA .safetensors file to load', required=False)
args = parser.parse_args()
torch_dtype = torch.bfloat16

sem = threading.Semaphore()

pipeline = QwenImageEditPlusPipeline.from_pretrained(
    args.model_dir,
    torch_dtype=torch_dtype,
    local_files_only=True,
)
print("pipeline loaded")
pipeline.to('cuda')

if args.lora is not None:
    pipeline.load_lora_weights(args.lora)


@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    prompt = data.get('prompt')
    steps = data.get('steps', 20)
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    init_images = data.get('init_images', [])

    data["init_images"] = "[omitted " + str(len(init_images)) + "]"
    print(data)
    if len(init_images) > 6 or len(init_images) == 0:
        return jsonify({'error': "Between 1 and 6 init images is required"}), 400
    generator = torch.Generator(device="cuda").manual_seed(seed)
    init_images_pillow = []
    for init_image in init_images:
        image_data = base64.b64decode(init_image)
        init_images_pillow.append(Image.open(io.BytesIO(image_data)))

    infotext = f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: Qwen-Image-Edit-2511'
    if args.lora is not None:
        infotext += f', LORA: {os.path.basename(args.lora)}'
    sem.acquire()
    print(f'Generating {infotext}')
    try:
        out_images = pipeline(
            image=init_images_pillow,
            prompt=prompt,
            num_inference_steps=steps,
            generator=generator,
            true_cfg_scale=4.0,
            negative_prompt=" ",
            guidance_scale=1.0,
            num_images_per_prompt=1,
        )

        print("Finished generating")
        return image_to_json_response(out_images.images[0], infotext)
    finally:
        sem.release()


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
