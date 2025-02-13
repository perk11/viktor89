import argparse
import base64
import io
import json
import random
import tempfile
import threading
import traceback
from io import BytesIO

from PIL import Image
from flask import Flask, request, jsonify
from OmniGen import OmniGenPipeline

parser = argparse.ArgumentParser(description="Inference server for Omnigen.")
parser.add_argument('--port', type=int, help='port to listen on')
args = parser.parse_args()

app = Flask(__name__)

# Load the model
pipeline = OmniGenPipeline.from_pretrained("Shitao/OmniGen-v1")
sem = threading.Semaphore()


@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_image():
    data = request.json

    prompt = data.get('prompt')
    seed = int(data.get('seed', 0))
    width = int(data.get('width', 1024))
    height = int(data.get('height', 1024))
    steps = int(data.get('steps', 35))
    init_images = data.get('init_images', [])

    data["init_images"]= "[omitted " + str(len(init_images)) + "]"
    print(data)
    images = []
    if len(init_images) > 5:
        return jsonify({'error': "Up to 5 images supported"}), 400
    for input_image in init_images:
        image_data = base64.b64decode(input_image)
        tmp_file = tempfile.NamedTemporaryFile(suffix='.jpg')
        file = open(tmp_file.name, 'wb')
        file.write(image_data)
        file.close()
        images.append(tmp_file)

    # Generate image
    if seed == 0:
        seed = random.randint(1, 99999999999999)
    try:
        sem.acquire()
        image = pipeline(
            prompt=prompt,
            height=height,
            width=width,
            seed=seed,
            num_inference_steps=steps,
            guidance_scale=2.5,
            img_guidance_scale=1.6,
            input_images=images,
        )[0]
    except Exception as e:
        print(e, flush=True)
        traceback.print_exc()
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
            'infotexts': [f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: {width}x{height}, Model: OmniGen-v1']
        })
    }

    return jsonify(response)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
