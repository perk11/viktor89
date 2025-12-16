import argparse
import base64
import os
import sys
import tempfile
import threading

import torch
import torchaudio as ta
from chatterbox.mtl_tts import ChatterboxMultilingualTTS
from chatterbox.models.tokenizers import tokenizer as cb_tokenizer
from flask import Flask, request, jsonify


parser = argparse.ArgumentParser(description="chatterbox tts server")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
args = parser.parse_args()

app = Flask(__name__)
root_dir = sys.argv[1]

#monkey patch chatterbox for Russian stress
thread_local_stresser = threading.local()
def add_russian_stress_thread_local(text: str) -> str:
    from russian_text_stresser.text_stresser import RussianTextStresser

    if not hasattr(thread_local_stresser, "stresser"):
        # Each thread lazily gets its own instance and its own SQLite connection.
        thread_local_stresser.stresser = RussianTextStresser()

    try:
        return thread_local_stresser.stresser.stress_text(text)
    except Exception:
        # On any failure, just fall back to the original text.
        return text
cb_tokenizer.add_russian_stress = add_russian_stress_thread_local

model = ChatterboxMultilingualTTS.from_pretrained(device="cuda")

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
    source_voice_format: str = data.get('source_voice_format')
    if prompt is None:
        return jsonify({'error': 'prompt is required'}), 400
    if language is None:
        return jsonify({'error': 'language is required'}), 400
    if source_voice is None:
        return jsonify({'error': 'source_voice is required'}), 400

    if source_voice_format is None or source_voice_format not in ['wav', 'ogg', 'mp3']:
        return jsonify({'error': 'source_voice_format must be one of wav, ogg, mp3, got: ' + str(source_voice_format)},
                       400)


    source_tmp_file = tempfile.NamedTemporaryFile(suffix='.' + source_voice_format)
    print("Writing received voice to " + source_tmp_file.file.name)
    source_tmp_file.write(base64.b64decode(source_voice))
    source_tmp_file.flush()
    speaker_wav = source_tmp_file.file.name

    out_tmp_wav_file = tempfile.NamedTemporaryFile(suffix='.wav')
    print("Generating voice to " + out_tmp_wav_file.file.name)
    wav = model.generate(prompt, audio_prompt_path=speaker_wav, language_id=language)
    source_tmp_file.close()
    ta.save(out_tmp_wav_file, wav, model.sr, format='wav')
    out_tmp_ogg_file = tempfile.NamedTemporaryFile(suffix='.ogg')
    print("Converting generated wav to " + out_tmp_wav_file.file.name)
    wav_to_opus_ogg(out_tmp_wav_file.name, out_tmp_ogg_file.name)
    out_tmp_wav_file.close()
    response = {
        'voice_data': base64.b64encode(out_tmp_ogg_file.read()).decode('utf-8'),
        'info': {
            prompt: prompt,
            # model: 'ChatterboxMultilingualTTS',
            language: language,
        }
    }
    out_tmp_ogg_file.close()

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
