import argparse
import base64
import io
import json
import random
import shutil
import threading

import numpy as np
from PIL import Image
from flask import Flask, request, jsonify

app = Flask(__name__)

parser = argparse.ArgumentParser(description="Inference server for BAGEL")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--model_dir', type=str, help='dir with model files', required=True)
parser.add_argument('--source_dir', type=str, help='dir with source files', required=True)
args = parser.parse_args()

# Load the model
import os
from io import BytesIO
import sys

sys.path.append(args.source_dir)
import torch
from accelerate import infer_auto_device_map, load_checkpoint_and_dispatch, init_empty_weights

from data.transforms import ImageTransform
from data.data_utils import pil_img2rgb, add_special_tokens
from modeling.bagel import (
    BagelConfig, Bagel, Qwen2Config, Qwen2ForCausalLM, SiglipVisionConfig, SiglipVisionModel
)
from modeling.qwen2 import Qwen2Tokenizer
from modeling.bagel.qwen2_navit import NaiveCache
from modeling.autoencoder import load_ae

offload_folder = '/dev/shm/BAGEL'
import signal


def receive_signal(signal, frame):
    print('Received signal:', signal)
    shutil.rmtree(offload_folder)
    sys.exit(0)


signal.signal(signal.SIGINT, receive_signal)
signal.signal(signal.SIGTERM, receive_signal)

model_path = args.model_dir  # Download from https://huggingface.co/ByteDance-Seed/BAGEL-7B-MoT

# LLM config preparing
llm_config = Qwen2Config.from_json_file(os.path.join(model_path, "llm_config.json"))
llm_config.qk_norm = True
llm_config.tie_word_embeddings = False
llm_config.layer_module = "Qwen2MoTDecoderLayer"

# ViT config preparing
vit_config = SiglipVisionConfig.from_json_file(os.path.join(model_path, "vit_config.json"))
vit_config.rope = False
vit_config.num_hidden_layers = vit_config.num_hidden_layers - 1

# VAE loading
vae_model, vae_config = load_ae(local_path=os.path.join(model_path, "ae.safetensors"))

# Bagel config preparing
config = BagelConfig(
    visual_gen=True,
    visual_und=True,
    llm_config=llm_config,
    vit_config=vit_config,
    vae_config=vae_config,
    vit_max_num_patch_per_side=70,
    connector_act='gelu_pytorch_tanh',
    latent_patch_size=2,
    max_latent_size=64,
)

with init_empty_weights():
    language_model = Qwen2ForCausalLM(llm_config)
    vit_model = SiglipVisionModel(vit_config)
    model = Bagel(language_model, vit_model, config)
    model.vit_model.vision_model.embeddings.convert_conv2d_to_linear(vit_config, meta=True)

# Tokenizer Preparing
tokenizer = Qwen2Tokenizer.from_pretrained(model_path)
tokenizer, new_token_ids, _ = add_special_tokens(tokenizer)

# Image Transform Preparing
vae_transform = ImageTransform(1024, 512, 16)
vit_transform = ImageTransform(980, 224, 14)

max_mem_per_gpu = "45GiB"  # Modify it according to your GPU setting

device_map = infer_auto_device_map(
    model,
    max_memory={i: max_mem_per_gpu for i in range(torch.cuda.device_count())},
    no_split_module_classes=["Bagel", "Qwen2MoTDecoderLayer"],
)
print(device_map)

same_device_modules = [
    'language_model.model.embed_tokens',
    'time_embedder',
    'latent_pos_embed',
    'vae2llm',
    'llm2vae',
    'connector',
    'vit_pos_embed'
]

for k in same_device_modules:
    device_map[k] = device_map[same_device_modules[0]]

model = load_checkpoint_and_dispatch(
    model,
    checkpoint=os.path.join(model_path, "ema.safetensors"),
    device_map=device_map,
    offload_buffers=True,
    offload_folder=offload_folder,
    dtype=torch.bfloat16,
)

model = model.eval()
print('Model loaded')

from inferencer import InterleaveInferencer

inferencer = InterleaveInferencer(
    model=model,
    vae_model=vae_model,
    tokenizer=tokenizer,
    vae_transform=vae_transform,
    vit_transform=vit_transform,
    new_token_ids=new_token_ids
)
generator = torch.Generator()
sem = threading.Semaphore()


@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data, flush=True)

    model_name = data.get('model', 'BAGEL')
    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 2 ** 32 - 1)))
    steps = int(data.get('steps', 50))

    if model_name == 'BAGEL':
        inference_hyper = dict(
            cfg_text_scale=4.0,
            cfg_img_scale=1.0,
            cfg_interval=[0.4, 1.0],
            timestep_shift=3.0,
            num_timesteps=steps,
            cfg_renorm_min=1.0,
            cfg_renorm_type="global",
        )
        think = False
    elif model_name == 'BAGEL-think':
        inference_hyper = dict(
            max_think_token_n=1000,
            do_sample=False,
            # text_temperature=0.3,
            cfg_text_scale=4.0,
            cfg_img_scale=1.0,
            cfg_interval=[0.4, 1.0],
            timestep_shift=3.0,
            num_timesteps=50,
            cfg_renorm_min=1.0,
            cfg_renorm_type="global",
        )
        think = True
    else:
        return jsonify({'error': "Unknown model: " + model_name}), 400
    return infer_image_to_json(model_name, prompt, seed, steps, inference_hyper, think)


def infer_image_to_json(model_name, prompt, seed, steps, inference_hyper, think, image=None):
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    if torch.cuda.is_available():
        torch.cuda.manual_seed(seed)
        torch.cuda.manual_seed_all(seed)
    sem.acquire()
    try:
        image = inferencer(text=prompt, think=think, image=image, **inference_hyper)['image']
    except Exception as e:
        print(e, flush=True)
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()
    buffered = BytesIO()
    image.save(buffered, format="PNG")
    image_base64 = base64.b64encode(buffered.getvalue()).decode('utf-8')
    response = {
        'images': [image_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Model: {model_name}-7B-MoT']
        })
    }
    return jsonify(response)


@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json

    model_name = data.get('model', 'BAGEL')
    prompt = data.get('prompt')
    seed = int(data.get('seed', random.randint(1, 2 ** 32 - 1)))
    steps = int(data.get('steps', 50))

    init_images = data.get('init_images', [])

    data["init_images"] = "[omitted " + str(len(init_images)) + "]"
    print(data, flush=True)
    if len(init_images) != 1:
        return jsonify({'error': "A single init image is required"}), 400

    image_data = base64.b64decode(init_images[0])
    image = Image.open(io.BytesIO(image_data))
    if model_name == 'BAGEL':
        inference_hyper = dict(
            cfg_text_scale=4.0,
            cfg_img_scale=2.0,
            cfg_interval=[0.0, 1.0],
            timestep_shift=4.0,
            num_timesteps=50,
            cfg_renorm_min=1.0,
            cfg_renorm_type="text_channel",
        )
        think = False
    elif model_name == 'BAGEL-think':
        inference_hyper = dict(
            max_think_token_n=1000,
            do_sample=False,
            # text_temperature=0.3,
            cfg_text_scale=4.0,
            cfg_img_scale=2.0,
            cfg_interval=[0.4, 1.0],
            timestep_shift=3.0,
            num_timesteps=50,
            cfg_renorm_min=0.0,
            cfg_renorm_type="text_channel",
        )
        think = True
    else:
        return jsonify({'error': "Unknown model: " + model_name}), 400
    return infer_image_to_json(model_name, prompt, seed, steps, inference_hyper, think, image)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
