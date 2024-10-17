import argparse
import base64
import json
from pathlib import Path

import cv2
import dlib
import numpy as np
from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="Inference server for Flux.1-dev-Controlnet-Upscaler.")
parser.add_argument('--port', type=int, help='port to listen on')
parser.add_argument('--model', type=str,
                    help='path to shape_predictor_68_face_landmarks_GTX.dat or other similar model')
args = parser.parse_args()

app = Flask(__name__)

# Load the pre-trained models for face detection and facial landmark detection
detector = dlib.get_frontal_face_detector()  # Face detector
predictor = dlib.shape_predictor(args.model)

# Load the clown nose image (PNG with transparency)
clown_nose_path = str(Path(__file__).with_name("clown_nose.png"))  # Replace with your PNG image path
clown_nose = cv2.imread(clown_nose_path, cv2.IMREAD_UNCHANGED)  # Load with alpha channel


@app.route('/sdapi/v1/img2img', methods=['POST'])
def generate_image():
    print("Got new request", flush=True)
    data = request.json
    # print(data)
    init_images = data.get('init_images', None)
    if init_images:
        image_data = base64.b64decode(init_images[0])
        nparr = np.frombuffer(image_data, np.uint8)
        image = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
    else:
        return jsonify({'error': "No image provided"}), 400

    try:
        image = add_clown_nose(image)
    except Exception as e:
        return jsonify({'error': str(e)}), 500

    # Convert image to base64
    _, png_image = cv2.imencode('.png', image)
    image_base64 = base64.b64encode(png_image.tobytes()).decode('utf-8')

    response = {
        'images': [image_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': ['']
        })
    }

    return jsonify(response)


def add_clown_nose(image):
    # Convert the image to grayscale
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

    # Detect faces in the image
    faces = detector(gray)

    # Loop through each detected face
    for face in faces:
        # Get the landmarks
        landmarks = predictor(gray, face)

        # Extract nose coordinates (points 28-36 correspond to the nose in the 68-point model)
        nose_tip = (landmarks.part(30).x, landmarks.part(30).y)  # Tip of the nose

        # Calculate the size of the clown nose (make it larger)
        nose_width = int(((landmarks.part(31).x - landmarks.part(35).x) ** 2 +
                          (landmarks.part(31).y - landmarks.part(35).y) ** 2) ** 0.5)
        nose_size = int(nose_width * 1.5)  # Make the clown nose 1.5 times bigger

        # Resize the clown nose image to the calculated size
        clown_nose_resized = cv2.resize(clown_nose, (nose_size, nose_size))

        # Get the dimensions of the resized clown nose
        nose_height, nose_width, _ = clown_nose_resized.shape

        # Calculate the position to place the clown nose (centered on the tip of the nose)
        top_left_x = int(nose_tip[0] - nose_width // 2)
        top_left_y = int(nose_tip[1] - nose_height // 2)

        # Ensure the nose doesn't go out of image bounds
        if top_left_x < 0:
            top_left_x = 0
        if top_left_y < 0:
            top_left_y = 0

        # Extract and handle transparency for blending
        alpha_s = clown_nose_resized[:, :, 3] / 255.0    # Alpha channel of the clown nose image
        alpha_l = 1.0 - alpha_s                         # Inverse of the alpha mask
        # Loop through each pixel in the resized clown nose image
        for i in range(nose_height):
            for j in range(nose_width):
                if top_left_y + i >= image.shape[0] or top_left_x + j >= image.shape[1]:
                    continue

                # Use the alpha values from the clown nose's transparent layer for smooth blending
                color_clown_nose = clown_nose_resized[i][j][:3]

                # Blend with background (original image) using the calculated alphas
                blended_color = (
                        alpha_s[i, j]*color_clown_nose +
                        alpha_l[i, j]*image[top_left_y+i, top_left_x+j])

                image[top_left_y + i, top_left_x + j] = blended_color
    return image


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
