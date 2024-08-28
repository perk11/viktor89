import base64
import json
import os
import tempfile
import threading

import torch
from diffusers import CogVideoXPipeline
from diffusers.utils import export_to_video
from flask import Flask, request, jsonify

app = Flask(__name__)

# Load the model
pipeline = CogVideoXPipeline.from_pretrained(
    "THUDM/CogVideoX-2b",
    torch_dtype=torch.bfloat16
)
pipeline.enable_model_cpu_offload()
pipeline.vae.enable_tiling()
generator = torch.Generator(device="cuda")
sem = threading.Semaphore()
@app.route('/txt2vid', methods=['POST'])
def generate_video():
    data = request.json
    print(data)

    prompt = data.get('prompt')
    seed = int(data.get('seed', 0))
    steps = int(data.get('steps', 50))
    frames = int(data.get('frames', 49))
    tmp_file = tempfile.NamedTemporaryFile(suffix='.mp4')
    video_contents_base64 = None

    # Generate image
    if seed == 0:
        seed = generator.seed()
    else:
        generator.manual_seed(seed)

    sem.acquire()
    try:
        video = pipeline(
            prompt=prompt,
            num_videos_per_prompt=1,
            num_inference_steps=steps,
            num_frames=frames,
            guidance_scale=6,
            generator=generator,
        ).frames[0]

        print("Video temporary file {}".format(tmp_file.name))
        export_to_video(video, tmp_file.name, fps=8)
        video_contents_base64 = base64.b64encode(tmp_file.read()).decode('utf-8')
    except Exception as e:
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
        os.remove(tmp_file.name)



    response = {
        'videos': [video_contents_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [f'{prompt}\nSteps: {steps}, frames: {frames}, Model: CogVideoX-2b']
        })
    }
    # Clear cache
    torch.cuda.empty_cache()

    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=18100)
