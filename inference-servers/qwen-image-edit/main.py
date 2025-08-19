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
from diffusers import AutoModel, DiffusionPipeline, TorchAoConfig
from diffusers.quantizers import PipelineQuantizationConfig
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

pipeline = DiffusionPipeline.from_pretrained(
    args.model_dir,
    torch_dtype=torch_dtype,
    use_safetensors=False,
)
pipeline.enable_model_cpu_offload()

if args.lora is not None:
    pipeline.load_lora_weights(args.lora)


@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    prompt = data.get('prompt')
    steps = data.get('steps', 8)
    seed = int(data.get('seed', random.randint(1, 99999999999999)))
    init_images = data.get('init_images', [])

    data["init_images"] = "[omitted " + str(len(init_images)) + "]"
    print(data)
    if len(init_images) != 1:
        return jsonify({'error': "A single init image is required"}), 400
    generator = torch.Generator(device="cuda").manual_seed(seed)
    image_data = base64.b64decode(init_images[0])
    image = Image.open(io.BytesIO(image_data))
    infotext = f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: Qwen-Image-Edit-torchao-int8wo'
    if args.lora is not None:
        infotext += f', LORA: {os.path.basename(args.lora)}'
    sem.acquire()
    print(f'Generating {infotext}')
    try:
        images = pipeline(
            image=image,
            prompt=prompt,
            num_inference_steps=steps,
            generator=generator,
        ).images

        print("Finished generating")
        return image_to_json_response(images[0], infotext)
    finally:
        sem.release()


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
