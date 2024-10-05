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
    print(data)

    prompt: str = data.get('prompt')
    language: str = data.get('language')
    speaker_id: str = data.get('speaker_id')
    source_voice: str = data.get('source_voice')
    source_voice_format: str = data.get('file_extension')
    if prompt is None:
        return jsonify({'error': 'prompt is required'}), 400
    if language is None:
        return jsonify({'error': 'language is required'}), 400
    if (speaker_id is None) and (source_voice is None):
        return jsonify({'error': 'speaker_id or source_voice is required'}), 400
    if (speaker_id is not None) and (source_voice is not None):
        return jsonify({'error': 'Only one of speaker_id/source_voice can be specified'}), 400

    if source_voice is not None and (source_voice_format is None or source_voice_format not in ['wav', 'ogg', 'mp3']):
        return jsonify({'error': 'source_voice_format must be one of wav, ogg, mp3'}), 400

    if source_voice is None:
        source_tmp_file = None
    else:
        source_tmp_file = tempfile.NamedTemporaryFile(suffix='.' + source_voice_format)
        source_tmp_file.write(base64.b64decode(source_voice))

    out_tmp_wav_file = tempfile.NamedTemporaryFile(suffix='.wav')

    tts.tts_to_file(text=prompt,
                    speaker_wav=source_tmp_file,
                    speaker=speaker_id,
                    language=language,
                    file_path=out_tmp_wav_file.file.name)
    if source_tmp_file is not None:
        source_tmp_file.close()
    out_tmp_ogg_file = tempfile.NamedTemporaryFile(suffix='.ogg')
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


if __name__ == '__main__':
    app.run(host='localhost', port=args.port)
