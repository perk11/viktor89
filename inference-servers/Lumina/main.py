#12274
import sys

from diffusers import LuminaText2ImgPipeline
import torch
import json
import threading

from flask import Flask, request, jsonify
import torch
import base64
from io import BytesIO

app = Flask(__name__)
root_dir = sys.argv[1]

pipeline = LuminaText2ImgPipeline.from_pretrained(
    root_dir, torch_dtype=torch.bfloat16
).to("cuda")
generator = torch.Generator()
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
            image = pipeline(
                prompt=prompt,
                height=height,
                width=width,
                num_inference_steps=steps,
                generator=generator,
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
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: Lumina-Next-SFT']
        })
    }
    # Clear cache
    torch.cuda.empty_cache()

    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=18092)

