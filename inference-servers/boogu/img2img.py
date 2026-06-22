import argparse
import base64
import gc
import os
import random
import sys
import threading
from io import BytesIO
from pathlib import Path

import torch
from PIL import Image, ImageOps
from boogu.pipelines.boogu.pipeline_boogu import BooguImagePipeline
from flask import Flask, request, jsonify

# Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))
from util.image_to_json import image_to_json_response

app = Flask(__name__)
generation_semaphore = threading.Semaphore()

def create_argument_parser() -> argparse.ArgumentParser:
    command_parser = argparse.ArgumentParser(description="Boogu Image Edit Inference Server")
    command_parser.add_argument('--port', type=int, default=5001, help='port to listen on')
    command_parser.add_argument('--model_dir', type=str, default="Boogu/Boogu-Image-0.1-Edit")
    command_parser.add_argument('--device', type=str, default="cuda:0")
    return command_parser

parsed_arguments = create_argument_parser().parse_args()

os.environ["device"] = parsed_arguments.device
target_execution_device = os.environ.get("device", "cuda:0")
computation_data_type = torch.bfloat16

image_edit_pipeline = BooguImagePipeline.from_pretrained(
    parsed_arguments.model_dir,
    torch_dtype=computation_data_type,
    trust_remote_code=True,
).to(target_execution_device)

def process_base64_to_rgb_image(base64_string: str) -> Image.Image:
    decoded_image_data = base64.b64decode(base64_string)
    loaded_image = Image.open(BytesIO(decoded_image_data))
    transposed_image = ImageOps.exif_transpose(loaded_image)
    if transposed_image.mode != "RGB":
        return transposed_image.convert("RGB")
    return transposed_image

@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_image_edit():
    if image_edit_pipeline is None:
        return jsonify({'error': 'Boogu Edit model not loaded or directory not found'}), 501

    request_payload = request.json
    print(f"Received img2img request. Keys: {list(request_payload.keys())}", flush=True)

    prompt_text = request_payload.get('prompt')
    negative_prompt_text = request_payload.get('negative_prompt', '')
    generation_seed = int(request_payload.get('seed', random.randint(1, 2**32 - 1)))
    inference_steps = int(request_payload.get('steps', 50))
    text_guidance_weight = float(request_payload.get('cfg_scale', 4.0))
    image_guidance_weight = float(request_payload.get('image_guidance_scale', 1.0))

    encoded_input_images = request_payload.get('init_images', [])
    if not encoded_input_images:
        return jsonify({'error': 'At least one init_image is required'}), 400

    parsed_input_images = []
    for encoded_image_string in encoded_input_images:
        parsed_input_images.append(process_base64_to_rgb_image(encoded_image_string))

    random_generator = torch.Generator(target_execution_device).manual_seed(generation_seed)

    generation_semaphore.acquire()
    try:
        generation_result = image_edit_pipeline(
            instruction=prompt_text,
            negative_instruction=negative_prompt_text,
            input_images=[parsed_input_images],
            height=None,
            width=None,
            max_input_image_pixels=2048 * 2048,
            max_input_image_side_length=2048 * 2,
            align_res=True,
            num_inference_steps=inference_steps,
            text_guidance_scale=text_guidance_weight,
            image_guidance_scale=image_guidance_weight,
            generator=random_generator,
        )

        # For img2img, we'll use the input image size in the response metadata as a fallback if needed
        # but generation_result might have the actual size.
        img = generation_result.images[0]
        return image_to_json_response(img,
                                      f'{prompt_text}\nSteps: {inference_steps}, Seed: {generation_seed}, Size: {img.width}x{img.height}, Model: Boogu-Edit')
    except Exception as execution_error:
        print(f"Error in img2img: {execution_error}", flush=True)
        return jsonify({'error': str(execution_error)}), 500

    finally:
        generation_semaphore.release()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
            torch.cuda.ipc_collect()
        gc.collect()

if __name__ == '__main__':
    app.run(host='localhost', port=parsed_arguments.port)
