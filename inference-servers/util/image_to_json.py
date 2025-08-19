import base64
import json
from io import BytesIO

from flask import jsonify


def image_to_json_response(image, infotext):
    buffered = BytesIO()
    image.save(buffered, format="PNG")
    image_base64 = base64.b64encode(buffered.getvalue()).decode('utf-8')

    response = {
        'images': [image_base64],
        'parameters': {},
        'info': json.dumps({
            'infotexts': [infotext]
        })
    }
    return jsonify(response)
