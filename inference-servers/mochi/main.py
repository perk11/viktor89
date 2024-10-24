import argparse
import base64
import json
import os
import random
import subprocess
import tempfile
import threading
import traceback

import numpy as np
import ray
from PIL import Image
from einops import rearrange
from flask import Flask, request, jsonify
from mochi_preview.handler import MochiWrapper

parser = argparse.ArgumentParser(description="Inference server for mochi.")
parser.add_argument('--port', type=int, default=5000, help='Port to listen on.')
parser.add_argument('--model_dir', required=True, help='Path to the model directory.')
args = parser.parse_args()

app = Flask(__name__)


print("Initializing ray")
ray.init()

MOCHI_DIR = args.model_dir
VAE_CHECKPOINT_PATH = f"{MOCHI_DIR}/vae.safetensors"
MODEL_CONFIG_PATH = f"{MOCHI_DIR}/dit-config.yaml"
MODEL_CHECKPOINT_PATH = f"{MOCHI_DIR}/dit.safetensors"
print("Creating MochiWrapper")
model = MochiWrapper(
    num_workers=1,  # Adjust based on your resources
    vae_stats_path=f"{MOCHI_DIR}/vae_stats.json",
    vae_checkpoint_path=VAE_CHECKPOINT_PATH,
    dit_config_path=MODEL_CONFIG_PATH,
    dit_checkpoint_path=MODEL_CHECKPOINT_PATH,
)
print("Done creating MochiWrapper")

sem = threading.Semaphore()


def linear_quadratic_schedule(num_steps, threshold_noise, linear_steps=None):
    if linear_steps is None:
        linear_steps = num_steps // 2
    linear_sigma_schedule = [i * threshold_noise / linear_steps for i in range(linear_steps)]
    threshold_noise_step_diff = linear_steps - threshold_noise * num_steps
    quadratic_steps = num_steps - linear_steps
    quadratic_coef = threshold_noise_step_diff / (linear_steps * quadratic_steps ** 2)
    linear_coef = threshold_noise / linear_steps - 2 * threshold_noise_step_diff / (quadratic_steps ** 2)
    const = quadratic_coef * (linear_steps ** 2)
    quadratic_sigma_schedule = [
        quadratic_coef * (i ** 2) + linear_coef * i + const
        for i in range(linear_steps, num_steps)
    ]
    sigma_schedule = linear_sigma_schedule + quadratic_sigma_schedule + [1.0]
    sigma_schedule = [1.0 - x for x in sigma_schedule]
    return sigma_schedule


def generate_video(
        prompt,
        negative_prompt,
        width,
        height,
        num_frames,
        seed,
        cfg_scale,
        num_inference_steps,
):
    sigma_schedule = linear_quadratic_schedule(num_inference_steps, 0.025)
    cfg_schedule = [cfg_scale] * num_inference_steps

    model_args = {
        "height": height,
        "width": width,
        "num_frames": num_frames,
        "mochi_args": {
            "sigma_schedule": sigma_schedule,
            "cfg_schedule": cfg_schedule,
            "num_inference_steps": num_inference_steps,
            "batch_cfg": True,
        },
        "prompt": [prompt],
        "negative_prompt": [negative_prompt],
        "seed": seed,
    }

    final_frames = None
    for cur_progress, frames, finished in model(model_args):
        final_frames = frames

    assert isinstance(final_frames, np.ndarray)
    assert final_frames.dtype == np.float32

    final_frames = rearrange(final_frames, "t b h w c -> b t h w c")
    final_frames = final_frames[0]

    # Create video using ffmpeg and save to a temporary file
    with tempfile.TemporaryDirectory() as tmpdir:
        frame_paths = []
        for i, frame in enumerate(final_frames):
            frame = (frame * 255).astype(np.uint8)
            frame_img = Image.fromarray(frame)
            frame_path = os.path.join(tmpdir, f"frame_{i:04d}.png")
            frame_img.save(frame_path)
            frame_paths.append(frame_path)

        frame_pattern = os.path.join(tmpdir, "frame_%04d.png")

        # Create a temporary file to store the video
        with tempfile.NamedTemporaryFile(suffix='.mp4', delete=False) as tmp_video_file:
            video_file_path = tmp_video_file.name
        ffmpeg_cmd = [
            'ffmpeg',
            '-y',
            '-r', '30',
            '-i', frame_pattern,
            '-vcodec', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-preset', 'veryslow',
            '-crf', '22',
            video_file_path
        ]
        try:
            process = subprocess.Popen(ffmpeg_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            stdout, stderr = process.communicate()

            if process.returncode != 0:
                print(f"ffmpeg error: {stderr.decode()}")
                raise Exception("ffmpeg failed")
            with open(video_file_path, 'rb') as f:
                video_data = f.read()
        finally:
            os.remove(video_file_path)

    return video_data


@app.route('/txt2vid', methods=['POST'])
def generate_video_route():
    data = request.json
    print(data,flush=True)
    prompt = data.get('prompt')
    negative_prompt = data.get('negative_prompt', '')
    width = int(data.get('width', 640))
    height = int(data.get('height', 480))
    num_frames = int(data.get('num_frames', 37))
    seed = int(data.get('seed', 0))
    cfg_scale = float(data.get('cfg_scale', 4.5))
    steps = int(data.get('steps', 100))

    if seed == 0:
        seed = random.randint(1, 2**32)

    if not prompt:
        return jsonify({'error': 'Prompt is required.'}), 400

    if width > 640 or height > 480:
        return jsonify({'error': 'Max size is 480'}), 400
    if num_frames > 37:
        return jsonify({'error': 'Max number of frames is 37'}), 400

    sem.acquire()
    try:
        video_data = generate_video(
            prompt,
            negative_prompt,
            width,
            height,
            num_frames,
            seed,
            cfg_scale,
            steps,
        )
        video_contents_base64 = base64.b64encode(video_data).decode('utf-8')
    except Exception as e:
        print(e)
        print(traceback.format_exc())
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()

    response = {
        'videos': [video_contents_base64],
        'parameters': {
            'width': width,
            'height': height,
            'num_frames': num_frames,
            'seed': seed,
            'cfg_scale': cfg_scale,
            'num_inference_steps': steps,
        },
        'info': json.dumps({
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: mochi-1-preview']
        })
    }
    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
