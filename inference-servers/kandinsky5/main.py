import argparse
import sys
import threading
from pathlib import Path
import random

from flask import Flask, request, jsonify

# Allow relative imports
file = Path(__file__).resolve()
parent, root = file.parent, file.parents[1]
sys.path.append(str(root))
from util.image_to_json import image_to_json_response
parser = argparse.ArgumentParser(description="Inference server for Chain-of-Zoom")
parser.add_argument('--source_dir', type=str,
                    help='path to Kandinsky-5 repo', required=True)
parser.add_argument('--port', type=int, help='port to listen on', required=True)
args = parser.parse_args()
sys.path.append(args.source_dir)

from kandinsky import get_T2I_pipeline

app = Flask(__name__)


pipeline = get_T2I_pipeline(resolution=1024,offload=True,
                            device_map={"dit": "cuda:0", "vae": "cuda:0", "text_embedder": "cuda:0"},
                            )
sem = threading.Semaphore()
@app.route('/sdapi/v1/txt2img', methods=['POST'])
def generate_image():
    data = request.json
    print(data)

    prompt = data.get('prompt')
    steps = int(data.get('steps', 25))
    seed = int(data.get('seed', random.randint(1, 2 ** 32 - 1)))

    sem.acquire()
    try:
        image = pipeline(
            prompt,
            num_steps=steps,
            seed=seed,
        )[0]
    except Exception as e:
        print(e)
        return jsonify({'error': str(e)}), 500
    finally:
        sem.release()

    infotext = f'{prompt}\nSteps: {steps}, Seed: {seed}, Size: 1024x1024, Model: kandinsky5lite_t2i'
    return image_to_json_response(image, infotext)


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
