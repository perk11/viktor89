#18200
import base64
import json
import sys
import threading
from io import BytesIO

import torch
from diffusers import EulerDiscreteScheduler
from diffusers import UNet2DConditionModel, AutoencoderKL
from flask import Flask
from flask import request, jsonify
from kolors.models.modeling_chatglm import ChatGLMModel
from kolors.models.tokenization_chatglm import ChatGLMTokenizer
# from PIL import Image
from kolors.pipelines.pipeline_stable_diffusion_xl_chatglm_256 import StableDiffusionXLPipeline

if len(sys.argv) > 1:
    root_dir = sys.argv[1]
else:
    root_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ckpt_dir = f'{root_dir}/weights/Kolors'
text_encoder = ChatGLMModel.from_pretrained(
    f'{ckpt_dir}/text_encoder',
    torch_dtype=torch.float16).half()
tokenizer = ChatGLMTokenizer.from_pretrained(f'{ckpt_dir}/text_encoder')
vae = AutoencoderKL.from_pretrained(f"{ckpt_dir}/vae", revision=None).half()
scheduler = EulerDiscreteScheduler.from_pretrained(f"{ckpt_dir}/scheduler")
unet = UNet2DConditionModel.from_pretrained(f"{ckpt_dir}/unet", revision=None).half()
pipe = StableDiffusionXLPipeline(
    vae=vae,
    text_encoder=text_encoder,
    tokenizer=tokenizer,
    unet=unet,
    scheduler=scheduler,
    force_zeros_for_empty_prompt=False)
pipe = pipe.to("cuda")
pipe.enable_model_cpu_offload()
generator = torch.Generator(pipe.device)

app = Flask(__name__)
sem = threading.Semaphore()
@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data)

    prompt = data.get('prompt')
    seed = int(data.get('seed', 0))
    steps = int(data.get('steps', 50))
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))
    # Generate image
    if seed == 0:
        seed = generator.seed()
    else:
        generator.manual_seed(seed)
    with torch.no_grad():
        sem.acquire()
        try:
            image = pipe(
                prompt=prompt,
                height=width,
                width=height,
                num_inference_steps=steps,
                guidance_scale=5.0,
                num_images_per_prompt=1,
                generator=generator).images[0]
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
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: Kolors']
        })
    }
    # Clear cache
    # torch.cuda.empty_cache()

    return jsonify(response)

if __name__ == '__main__':
    app.run(host='localhost', port=18093)
