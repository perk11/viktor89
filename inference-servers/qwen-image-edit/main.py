import argparse
import base64
import gc
import io
import os
import random
import sys
import threading
from pathlib import Path

import torch

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

from PIL import Image, ImageOps

MAX_INIT_IMAGE_SIDE = 1344


def _prepare_init_image(pil_image: Image.Image, max_side: int = MAX_INIT_IMAGE_SIDE) -> Image.Image:
    pil_image = ImageOps.exif_transpose(pil_image)
    if pil_image.mode != "RGB":
        pil_image = pil_image.convert("RGB")

    width, height = pil_image.size
    if width <= max_side and height <= max_side:
        return pil_image

    if width >= height:
        target_width = max_side
        target_height = max(1, round(height * (max_side / width)))
    else:
        target_height = max_side
        target_width = max(1, round(width * (max_side / height)))

    return pil_image.resize((target_width, target_height), resample=Image.LANCZOS)

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
    if len(init_images) > 3 or len(init_images) == 0:
        return jsonify({'error': "Between 1 and 6 init images is required"}), 400

    generator = torch.Generator(device="cuda").manual_seed(seed)
    init_images_pillow = []
    for init_image in init_images:
        image_data = base64.b64decode(init_image)
        opened_image = Image.open(io.BytesIO(image_data))
        init_images_pillow.append(_prepare_init_image(opened_image))

    infotext = f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: Qwen-Image-Edit-2511'
    if args.lora is not None:
        infotext += f', LORA: {os.path.basename(args.lora)}'
    out_images = None
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
       # Drop refs to large temporaries, then clear CUDA allocator cache.
       del out_images
       del generator
       del init_images_pillow
       if torch.cuda.is_available():
           torch.cuda.synchronize()
           torch.cuda.empty_cache()
           torch.cuda.ipc_collect()
       gc.collect()
       sem.release()


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
