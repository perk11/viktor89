import argparse
import base64
import io
import json
import threading
from io import BytesIO

import torch
from PIL import Image
from diffusers import FluxControlNetModel
from diffusers.pipelines import FluxControlNetPipeline
from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="Inference server for Flux.1-dev-Controlnet-Upscaler.")
parser.add_argument('--port', type=int, help='port to listen on')
args = parser.parse_args()

app = Flask(__name__)

controlnet = FluxControlNetModel.from_pretrained(
    "jasperai/Flux.1-dev-Controlnet-Upscaler",
    torch_dtype=torch.bfloat16
)
pipe = FluxControlNetPipeline.from_pretrained(
    "black-forest-labs/FLUX.1-dev",
    controlnet=controlnet,
    torch_dtype=torch.bfloat16
)
pipe.enable_sequential_cpu_offload() #save some VRAM by offloading the model to CPU. Remove this if you have enough GPU power

generator = torch.Generator()
sem = threading.Semaphore()
@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_image():
    print("Got new request", flush=True)
    data = request.json
    # print(data)
    init_images = data.get('init_images', None)
    if init_images:
        image_data = base64.b64decode(init_images[0])
        control_image = Image.open(io.BytesIO(image_data))
    else:
        return jsonify({'error': "No image provided"}), 400
    seed = int(data.get('seed', 0))
    steps = int(data.get('steps', 28))
    w, h = control_image.size

    new_w, new_h = 4 * w, 4 * h
    if new_w > 1280 or new_h > 1280:
        scale_factor = min(1280 / new_w, 1280 / new_h)
        new_w = int(new_w * scale_factor)
        new_h = int(new_h * scale_factor)

    # Ensure new_w and new_h are divisible by 8
    new_w = (new_w // 8) * 8
    new_h = (new_h // 8) * 8

    print(f"Resizing to {new_w},{new_h}", flush=True)


    control_image = control_image.resize((new_w,new_h))
    cfg_scale = 3.5
    # Generate image
    if seed == 0:
        seed = generator.seed()
    else:
        generator.manual_seed(seed)
    with torch.no_grad():
        sem.acquire()
        try:
            image = pipe(
                prompt="",
                control_image=control_image,
                controlnet_conditioning_scale=0.6,
                num_inference_steps=steps,
                guidance_scale=3.5,
                height=control_image.size[1],
                width=control_image.size[0]
            ).images[0]
        except Exception as e:
            return jsonify({'error': str(e)}), 500
        finally:
            sem.release()

        # Convert image to base64
        buffered = BytesIO()
        image.save(buffered, format="PNG")
        image_base64 = base64.b64encode(buffered.getvalue()).decode('utf-8')

        response = {
            'images': [image_base64],
            'parameters': {},
            'info': json.dumps({
                'infotexts': [f'Steps: {steps}, CFG scale: {cfg_scale}, Seed: {seed}, Size: {new_w}x{new_h}, Model: Flux.1-dev-Controlnet-Upscaler']
            })
        }
        # Clear cache
        torch.cuda.empty_cache()

        return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost',  port=args.port)
