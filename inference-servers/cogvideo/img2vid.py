import argparse
import base64
import io
import json
import os
import tempfile
import threading

import torch
from PIL import Image
from diffusers import (
    CogVideoXImageToVideoPipeline,
    CogVideoXDPMScheduler,
)

from diffusers.utils import export_to_video
from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="Inference server for cogvideo.")
parser.add_argument('--port', type=int, help='port to listen on')
args = parser.parse_args()

app = Flask(__name__)

model = "CogVideoX-5B-I2V"
print("Loading model {}".format(model))
pipeline = CogVideoXImageToVideoPipeline.from_pretrained(
    "THUDM/" + model,
    torch_dtype=torch.bfloat16
)

pipeline.vae.enable_slicing()
pipeline.vae.enable_tiling()
pipeline.enable_model_cpu_offload()
pipeline.scheduler = CogVideoXDPMScheduler.from_config(pipeline.scheduler.config, timestep_spacing="trailing")

generator = torch.Generator(device="cuda")
sem = threading.Semaphore()
@app.route('/img2vid', methods=['POST'])
def generate_video():
    data = request.json
    print("Got new request", flush=True)
    # print(data)
    init_images = data.get('init_images', None)
    if init_images:
        image_data = base64.b64decode(init_images[0])
        image = Image.open(io.BytesIO(image_data))
    else:
        return jsonify({'error': "No image provided"}), 400
    prompt = data.get('prompt')
    seed = int(data.get('seed', 0))
    steps = int(data.get('steps', 50))
    frames = int(data.get('frames', 49))
    tmp_file = tempfile.NamedTemporaryFile(suffix='.mp4')

    # Generate image
    if seed == 0:
        seed = generator.seed()
    else:
        generator.manual_seed(seed)

    print("Generating img2vid for prompt \"{0}\"".format(str(prompt)))
    sem.acquire()
    try:
        video = pipeline(
            image=image,
            prompt=prompt,
            num_videos_per_prompt=1,
            num_inference_steps=steps,
            num_frames=frames,
            guidance_scale=6,
            generator=generator,
        ).frames[0]

        print("Video temporary file {}".format(tmp_file.name), flush=True)
        export_to_video(video, tmp_file.name, fps=8)
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
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Frames: {frames}, Model: {model}']
        })
    }
    # Clear cache
    torch.cuda.empty_cache()

    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
