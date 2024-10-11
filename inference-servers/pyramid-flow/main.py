import argparse
import base64
import json
import tempfile
import threading

import torch
from diffusers.utils import export_to_video
from flask import Flask, request, jsonify

import sys
sys.path.append('/home/perk11/LLM/pyramid-flow')
from pyramid_dit import PyramidDiTForVideoGeneration

parser = argparse.ArgumentParser(description="Inference server for pyramid-flowr.")
parser.add_argument('--port', type=int, help='port to listen on')
args = parser.parse_args()

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

generator = torch.Generator()
sem = threading.Semaphore()


@app.route('/txt2vid', methods=['POST'])
def generate_image():
    print("Got new request", flush=True)
    data = request.json
    print(data)
    prompt = data.get('prompt')
    seed = int(data.get('seed', 0))

    if seed == 0:
        seed = generator.seed()
    else:
        generator.manual_seed(seed)

    with torch.no_grad(), torch.cuda.amp.autocast(enabled=True, dtype=torch_dtype):
        frames = model.generate(
            prompt=prompt,
            num_inference_steps=[20, 20, 20],
            video_num_inference_steps=[10, 10, 10],
            height=768,
            width=1280,
            temp=16,  # temp=16: 5s, temp=31: 10s
            guidance_scale=9.0,  # The guidance for the first frame
            video_guidance_scale=5.0,  # The guidance for the other video latent
            output_type="pil",
            save_memory=True,  # If you have enough GPU memory, set it to `False` to improve vae decoding speed
            generator=generator,
        )
        tmp_file = tempfile.NamedTemporaryFile(suffix='.mp4')
        print("Video temporary file {}".format(tmp_file.name))
        export_to_video(frames, tmp_file.name, fps=24)
        video_contents_base64 = base64.b64encode(tmp_file.read()).decode('utf-8')

    response = {
        'videos': [video_contents_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [f'{prompt}\n, Seed: {seed}, Model: pyramid-flow-768p']
        })
    }
    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
