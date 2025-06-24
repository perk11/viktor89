import argparse
import base64
import io
import json
import random
import sys
import threading
import traceback
from io import BytesIO

import torch
from PIL import Image
from accelerate import Accelerator
# from diffusers.hooks import apply_group_offloading
from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="Inference server for Omnigen2.")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--source_dir', type=str, help='path to OmniGenv2 repo', required=True)
parser.add_argument('--model-path', type=str, default="OmniGen2/OmniGen2")
args = parser.parse_args()
sys.path.append(args.source_dir)

from omnigen2.models.transformers.transformer_omnigen2 import OmniGen2Transformer2DModel
from omnigen2.pipelines.omnigen2.pipeline_omnigen2 import OmniGen2Pipeline
from torchvision.transforms.functional import to_pil_image, to_tensor

app = Flask(__name__)
dtype='bf16'
# Load the model
accelerator = Accelerator(mixed_precision=dtype if dtype != 'fp32' else 'no')
weight_dtype = torch.bfloat16
from transformers import CLIPProcessor
pipeline = OmniGen2Pipeline.from_pretrained(
    args.model_path,
    processor=CLIPProcessor.from_pretrained(
        args.model_path,
        subfolder="processor",
        use_fast=True
    ),
    torch_dtype=weight_dtype,
    trust_remote_code=True,
)
transformer = OmniGen2Transformer2DModel.from_pretrained(
    args.model_path,
    subfolder="transformer",
    torch_dtype=weight_dtype,
)
pipeline.register_modules(transformer=transformer)

pipeline = pipeline.to(accelerator.device)
sem = threading.Semaphore()


@app.route('/sdapi/v1/img2img', methods=['POST'])
def img2img():
    data = request.json

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt')
    seed = int(data.get('seed', 0))
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))
    steps = int(data.get('steps', 50))
    init_images = data.get('init_images', [])

    data["init_images"]= "[omitted " + str(len(init_images)) + "]"
    print(data)
    images = []
    if len(init_images) > 5:
        return jsonify({'error': "Up to 5 images supported"}), 400
    for input_image in init_images:
        image_data = base64.b64decode(input_image)
        pil_img: Image.Image = Image.open(io.BytesIO(image_data))
        images.append(pil_img)

    # Generate image
    if seed == 0:
        seed = random.randint(1, 99999999999999)

    generator = torch.Generator(device=accelerator.device).manual_seed(seed)
    try:
        sem.acquire()
        image = pipeline(
            prompt=prompt,
            input_images=images,
            width=width,
            height=height,
            num_inference_steps=steps,
            max_sequence_length=1024,
            text_guidance_scale=5.0,
            image_guidance_scale=2.0,
            cfg_range=(0.0, 1.0),
            negative_prompt=negative_prompt,
            num_images_per_prompt=1,
            generator=generator,
            output_type="pil",
        ).images[0]
    except Exception as e:
        print(e, flush=True)
        traceback.print_exc()
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
    return json_from_pil_image(height, image, prompt, seed, steps, width)


@app.route('/sdapi/v1/txt2img', methods=['POST'])
def txt2img():
    data = request.json

    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt')
    seed = int(data.get('seed', 0))
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))
    steps = int(data.get('steps', 50))

    print(data)

    if seed == 0:
        seed = random.randint(1, 99999999999999)

    generator = torch.Generator(device=accelerator.device).manual_seed(seed)
    try:
        sem.acquire()
        image = pipeline(
            prompt=prompt,
            input_images=None,
            width=width,
            height=height,
            num_inference_steps=steps,
            max_sequence_length=1024,
            text_guidance_scale=5.0,
            image_guidance_scale=2.0,
            cfg_range=(0.0, 0.6),
            negative_prompt=negative_prompt,
            num_images_per_prompt=1,
            generator=generator,
            output_type="pil",
        ).images[0]
    except Exception as e:
        print(e, flush=True)
        traceback.print_exc()
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
    return json_from_pil_image(height, image, prompt, seed, steps, width)


def json_from_pil_image(height, image, prompt, seed, steps, width):
    buffered = BytesIO()
    image.save(buffered, format="PNG")
    image_base64 = base64.b64encode(buffered.getvalue()).decode('utf-8')
    response = {
        'images': [image_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: OmniGen-v2']
        })
    }
    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
