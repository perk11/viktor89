import argparse
import base64
import io
import json
import random
import sys
import threading
from pathlib import Path

from PIL import Image
from flask import Flask, request, jsonify
from transformers import pipeline

#Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))

from util.image_to_json import image_to_json_response

app = Flask(__name__)
parser = argparse.ArgumentParser(description="rmbg inference")
parser.add_argument('--port', type=int, help='port to listen on')
args = parser.parse_args()

semaphore = threading.Semaphore()
pipe = pipeline("image-segmentation", model="briaai/RMBG-1.4", trust_remote_code=True)

@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_img2img():
    data = request.json
    init_images = data.get('init_images', [])

    data["init_images"]= "[omitted " + str(len(init_images)) + "]"
    print(data)
    if len(init_images) != 1:
        return jsonify({"error": "A single image is required in init_images"}), 400
    if len(init_images) > 10:
        return jsonify({"error": "Too many init images"}), 400
    image_data = base64.b64decode(init_images[0])
    image = Image.open(io.BytesIO(image_data))
    semaphore.acquire()
    print("Acquired lock")
    pillow_mask = pipe(image, return_mask = True) # outputs a pillow mask
    pillow_image = pipe(image) # applies mask on input and returns a pillow image
    semaphore.release()
    return image_to_json_response(pillow_image, "Model: RMBG-1.4")
if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
