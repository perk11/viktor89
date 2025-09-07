import base64
import io
import json
import urllib.parse
import urllib.request
import urllib.error
import uuid
import websocket  # NOTE: websocket-client (https://github.com/websocket-client/websocket-client)
from flask import jsonify


def _queue_prompt(prompt, client_id, comfy_ui_server_address):
    p = {"prompt": prompt, "client_id": client_id}
    data = json.dumps(p).encode('utf-8')
    req = urllib.request.Request("http://{}/prompt".format(comfy_ui_server_address), data=data)
    try:
        comfy_response = urllib.request.urlopen(req).read()
        print("Comfy response: {}".format(comfy_response), flush=True)
    except urllib.error.HTTPError  as e:
        error_body = e.read().decode("utf-8", errors="replace")
        print(f"Sending prompt to comfy resulted in HTTPError: {e.code} {e.reason}:\n{error_body}", flush=True)
        raise e
    return json.loads(comfy_response)

def get_images(prompt, comfy_ui_server_address):
    client_id = str(uuid.uuid4())
    ws = websocket.WebSocket()
    ws.connect("ws://{}/ws?clientId={}".format(comfy_ui_server_address, client_id))
    prompt_id = _queue_prompt(prompt, client_id, comfy_ui_server_address)['prompt_id']
    output_images = {}
    current_node = ""
    while True:
        out = ws.recv()
        if isinstance(out, str):
            message = json.loads(out)
            if message['type'] == 'executing':
                data = message['data']
                if 'prompt_id' in data and data['prompt_id'] == prompt_id:
                    if data['node'] is None:
                        break  #Execution is done
                    else:
                        current_node = data['node']
        else:
            if current_node == 'save_image_websocket_node':
                images_output = output_images.get(current_node, [])
                images_output.append(out[8:])
                output_images[current_node] = images_output

    ws.close()
    return output_images

def get_audio(workflow, comfy_ui_server_address):
    client_id = str(uuid.uuid4())
    ws = websocket.WebSocket()
    ws.connect("ws://{}/ws?clientId={}".format(comfy_ui_server_address, client_id))
    prompt_id = _queue_prompt(workflow, client_id, comfy_ui_server_address)['prompt_id']
    output_audios = []
    while True:
        out = ws.recv()
        if isinstance(out, str):
            print(out, flush=True)
            message = json.loads(out)
            if message['type'] == 'executing':
                data = message['data']
                if 'prompt_id' in data and data['prompt_id'] == prompt_id:
                    if data['node'] is None:
                        break  #Execution is done
                    else:
                        current_node = data['node']
            elif message['type'] == 'executed':
                data = message['data']
                if data['output'] is None:
                    continue
                print("Received audio from Comfy", flush=True)
                for audio in data['output']['audio']:
                    filename = audio['filename']
                    subfolder = audio['subfolder']
                    url = "http://{}/api/view?filename={}&type=output&subfolder={}".format(
                        comfy_ui_server_address,
                        urllib.parse.quote_plus(filename),
                        urllib.parse.quote_plus(subfolder),
                    )
                    file = urllib.request.urlopen(url).read()
                    output_audios.append(file)

    ws.close()
    return output_audios

def json_image_response_from_images_list(images, infotext):
    for node_id in images:
        for image_data in images[node_id]:
            image_base64 = base64.b64encode(io.BytesIO(image_data).getbuffer()).decode('utf-8')
            response = {
                'images': [image_base64],
                'parameters': {},
                'info': json.dumps({
                    'infotexts': [infotext]
                })
            }

            return jsonify(response)
    return jsonify({
        'error': 'No images received from ComfyUI'
    })


def comfy_workflow_to_json_image_response(comfy_workflow_object, comfy_ui_server_address, infotext):
    images = get_images(comfy_workflow_object, comfy_ui_server_address)
    print(f"{len(images)} images received from Comfy", flush=True)
    return json_image_response_from_images_list(images, infotext)
