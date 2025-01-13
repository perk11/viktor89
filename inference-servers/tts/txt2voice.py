import argparse
import base64
import os
import sys
import tempfile
import threading

import torch
from TTS.api import TTS  # pip install coqui-tts
from flask import Flask, request, jsonify

parser = argparse.ArgumentParser(description="text2voice server")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
args = parser.parse_args()

app = Flask(__name__)
root_dir = sys.argv[1]

model = "tts_models/multilingual/multi-dataset/xtts_v2"
tts = TTS(model).to('cuda')

generator = torch.Generator()
sem = threading.Semaphore()


@app.route('/txt2voice', methods=['POST'])
def generate_image():
    data = request.json
    print_dictionary(data)

    prompt: str = data.get('prompt')
    language: str = data.get('language')
    speaker_id: str = data.get('speaker_id')
    source_voice: str = data.get('source_voice')
    speed: float = data.get('speed', 1.0)
    print(speed, flush=True)
    source_voice_format: str = data.get('source_voice_format')
    if prompt is None:
        return jsonify({'error': 'prompt is required'}), 400
    if language is None:
        return jsonify({'error': 'language is required'}), 400
    if (speaker_id is None) and (source_voice is None):
        return jsonify({'error': 'speaker_id or source_voice is required'}), 400
    if (speaker_id is not None) and (source_voice is not None):
        return jsonify({'error': 'Only one of speaker_id/source_voice can be specified'}), 400

    if source_voice is not None and (source_voice_format is None or source_voice_format not in ['wav', 'ogg', 'mp3']):
        return jsonify({'error': 'source_voice_format must be one of wav, ogg, mp3, got: ' + str(source_voice_format)},
                       400)

    if source_voice is None:
        source_tmp_file = None
        speaker_wav = None
    else:
        source_tmp_file = tempfile.NamedTemporaryFile(suffix='.' + source_voice_format)
        print("Writing received voice to " + source_tmp_file.file.name)
        source_tmp_file.write(base64.b64decode(source_voice))
        source_tmp_file.flush()
        speaker_wav = source_tmp_file.file.name

    out_tmp_wav_file = tempfile.NamedTemporaryFile(suffix='.wav')
    print("Generating voice to " + out_tmp_wav_file.file.name)

    tts.tts_to_file(text=prompt,
                    speaker_wav=speaker_wav,
                    speaker=speaker_id,
                    language=language,
                    speed=speed,
                    file_path=out_tmp_wav_file.file.name)
    if source_tmp_file is not None:
        source_tmp_file.close()
    out_tmp_ogg_file = tempfile.NamedTemporaryFile(suffix='.ogg')
    print("Converting generated wav to " + out_tmp_wav_file.file.name)
    wav_to_opus_ogg(out_tmp_wav_file.name, out_tmp_ogg_file.name)
    out_tmp_wav_file.close()
    response = {
        'voice_data': base64.b64encode(out_tmp_ogg_file.read()).decode('utf-8'),
        'info': {
            prompt: prompt,
            model: model,
            language: language,
        }
    }
    out_tmp_ogg_file.close()
    # Clear cache
    torch.cuda.empty_cache()

    return jsonify(response)


def wav_to_opus_ogg(input_file: str, output_file: str):
    shell_command = f'ffmpeg -y -i {input_file} -c:a libopus {output_file}'
    os.system(shell_command)


def print_dictionary(dictionary):
    items = list(dictionary.items())
    for i, (key, value) in enumerate(items):
        value_str = str(value)
        if len(value_str) > 256:
            value_str = value_str[:256] + '...'
        print(f"'{key}': '{value_str}'", flush=(i == len(items) - 1))


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
