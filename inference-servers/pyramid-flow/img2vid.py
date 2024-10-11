import argparse
import base64
import io
import json
import os
import tempfile
import threading

import torch
from PIL import Image
from diffusers.utils import export_to_video
from flask import Flask, request, jsonify

import sys
sys.path.append('/home/perk11/LLM/pyramid-flow')
from pyramid_dit import PyramidDiTForVideoGeneration

parser = argparse.ArgumentParser(description="img2vid inference server for pyramid-flow.")
parser.add_argument('--port', type=int, help='port to listen on')
args = parser.parse_args()

app = Flask(__name__)

app = Flask(__name__)
torch.cuda.set_device(0)
model_dtype, torch_dtype = 'bf16', torch.bfloat16  # Use bf16, fp16 or fp32

model = PyramidDiTForVideoGeneration(
    '/var/models/pyramid-flow',  # The downloaded checkpoint dir
    model_dtype,
    model_variant='diffusion_transformer_768p',  # 'diffusion_transformer_384p'
)
model.vae.to("cuda")
model.dit.to("cuda")
model.text_encoder.to("cuda")
model.vae.enable_tiling()
generator = torch.Generator(device="cuda")
sem = threading.Semaphore()
@app.route('/img2vid', methods=['POST'])
def generate_video():
    data = request.json
    prompt = data.get('prompt')
    seed = int(data.get('seed', 0))
    steps = int(data.get('steps', 10))
    print("Got new request", flush=True)
    # print(data)
    init_images = data.get('init_images', None)
    if init_images:
        image_data = base64.b64decode(init_images[0])
        image = Image.open(io.BytesIO(image_data))
        image = image.convert("RGB").resize((1280, 768))
    else:
        return jsonify({'error': "No image provided"}), 400
    tmp_file = tempfile.NamedTemporaryFile(suffix='.mp4')

    # Generate image
    if seed == 0:
        seed = generator.seed()
    else:
        generator.manual_seed(seed)

    print("Generating img2vid for prompt \"{0}\"".format(str(prompt)), flush=True)
    sem.acquire()
    try:
        with torch.no_grad(), torch.cuda.amp.autocast(enabled=True, dtype=torch_dtype):
            frames = model.generate_i2v(
                prompt=prompt,
                input_image=image,
                num_inference_steps=[steps, steps, steps],
                temp=16,
                video_guidance_scale=4.0,
                output_type="pil",
                save_memory=True,
            )
            tmp_file = tempfile.NamedTemporaryFile(suffix='.mp4')
            print("Video temporary file {}".format(tmp_file.name))
            export_to_video(frames, tmp_file.name, fps=24)
            video_contents_base64 = base64.b64encode(tmp_file.read()).decode('utf-8')
    except Exception as e:
        print(e, flush=True)
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
        os.remove(tmp_file.name)

    response = {
        'videos': [video_contents_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Model:  pyramid-flow-768p']
        })
    }
    # Clear cache
    torch.cuda.empty_cache()

    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
