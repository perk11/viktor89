import argparse
import base64
import math
import os
import sys
import tempfile
import threading
from pathlib import Path

import torch
import torchaudio
from sam_audio import SAMAudio, SAMAudioProcessor
from flask import Flask, request, jsonify


parser = argparse.ArgumentParser(description="chatterbox tts server")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
args = parser.parse_args()

app = Flask(__name__)
root_dir = sys.argv[1]

device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
model = SAMAudio.from_pretrained("facebook/sam-audio-large").to(device).eval()
processor = SAMAudioProcessor.from_pretrained("facebook/sam-audio-large")

generator = torch.Generator()
sem = threading.Semaphore()


@app.route('/sound-and-prompt-to-target-and-residual', methods=['POST'])
def generate_target_and_residual():
    data = request.json
    print_dictionary(data)

    prompt: str = data.get('prompt')
    audio: str = data.get('audio')

    if not prompt or not audio:
        return jsonify({"error": "Missing 'prompt' or 'audio'"}), 400

    source_tmp_file = tempfile.NamedTemporaryFile(suffix='.ogg', delete=False)
    try:
        print("Writing received audio to " + source_tmp_file.name)
        source_tmp_file.write(base64.b64decode(audio))
        source_tmp_file.flush()
        source_tmp_file.close()
        source_tmp_path = source_tmp_file.name

        target_sampling_rate = int(processor.audio_sampling_rate)
        waveform, original_sampling_rate = torchaudio.load(source_tmp_path)  # [channels, samples]
        waveform = waveform.to(torch.float32)

        if waveform.shape[0] > 1:
            waveform = waveform.mean(dim=0, keepdim=True)

        if original_sampling_rate != target_sampling_rate:
            waveform = torchaudio.functional.resample(
                waveform, orig_freq=original_sampling_rate, new_freq=target_sampling_rate
            )

        total_samples = waveform.shape[-1]
        chunk_seconds = 30.0
        chunk_samples = int(round(chunk_seconds * target_sampling_rate))
        num_chunks = int(math.ceil(total_samples / chunk_samples))

        reconstructed_target_chunks = []
        reconstructed_residual_chunks = []

        sem.acquire()
        for chunk_index in range(num_chunks):
            start_sample = chunk_index * chunk_samples
            end_sample = min((chunk_index + 1) * chunk_samples, total_samples)
            chunk_waveform = waveform[:, start_sample:end_sample]  # [1, chunk_len]
            expected_chunk_length = chunk_waveform.shape[-1]

            target_chunk, residual_chunk = run_separation_for_chunk(
                chunk_waveform, target_sampling_rate, prompt
            )

            if target_chunk.shape[-1] > expected_chunk_length:
                target_chunk = target_chunk[..., :expected_chunk_length]
            if residual_chunk.shape[-1] > expected_chunk_length:
                residual_chunk = residual_chunk[..., :expected_chunk_length]

            if target_chunk.ndim == 1:
                target_chunk = target_chunk.unsqueeze(0)
            if residual_chunk.ndim == 1:
                residual_chunk = residual_chunk.unsqueeze(0)

            reconstructed_target_chunks.append(target_chunk)
            reconstructed_residual_chunks.append(residual_chunk)
        sem.release()

        if len(reconstructed_target_chunks) == 0:
            return jsonify({"error": "No chunks were processed"}), 500

        merged_target = torch.cat(reconstructed_target_chunks, dim=-1)[:, :total_samples]
        merged_residual = torch.cat(reconstructed_residual_chunks, dim=-1)[:, :total_samples]

        output_target_tmp_wav_file = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
        output_residual_tmp_wav_file = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
        output_target_tmp_wav_file.close()
        output_residual_tmp_wav_file.close()

        print("Saving audio to " + output_target_tmp_wav_file.name)
        torchaudio.save(output_target_tmp_wav_file.name, merged_target.cpu(), target_sampling_rate)
        print("Saving audio to " + output_residual_tmp_wav_file.name)
        torchaudio.save(output_residual_tmp_wav_file.name, merged_residual.cpu(), target_sampling_rate)

        output_target_ogg_file = tempfile.NamedTemporaryFile(suffix='.ogg', delete=False)
        output_residual_ogg_file = tempfile.NamedTemporaryFile(suffix='.ogg', delete=False)
        output_target_ogg_file.close()
        output_residual_ogg_file.close()

        print("Converting generated target to " + output_target_ogg_file.name)
        wav_to_opus_ogg(output_target_tmp_wav_file.name, output_target_ogg_file.name)
        print("Converting generated residual to " + output_residual_ogg_file.name)
        wav_to_opus_ogg(output_residual_tmp_wav_file.name, output_residual_ogg_file.name)

        with open(output_target_ogg_file.name, "rb") as f:
            target_b64 = base64.b64encode(f.read()).decode("utf-8")
        with open(output_residual_ogg_file.name, "rb") as f:
            residual_b64 = base64.b64encode(f.read()).decode("utf-8")

        response = {"target": target_b64, "residual": residual_b64}
        return jsonify(response)

    finally:
        for path_to_remove in [
            getattr(source_tmp_file, "name", None),
            locals().get("output_target_tmp_wav_file").name if "output_target_tmp_wav_file" in locals() else None,
            locals().get("output_residual_tmp_wav_file").name if "output_residual_tmp_wav_file" in locals() else None,
            locals().get("output_target_ogg_file").name if "output_target_ogg_file" in locals() else None,
            locals().get("output_residual_ogg_file").name if "output_residual_ogg_file" in locals() else None,
        ]:
            if path_to_remove and os.path.exists(path_to_remove):
                try:
                    os.remove(path_to_remove)
                except OSError:
                    pass


def run_separation_for_chunk(chunk_waveform_1ch: torch.Tensor, target_sampling_rate, prompt) -> tuple[torch.Tensor, torch.Tensor]:
    # chunk_waveform_1ch: [1, chunk_len]
    try:
        inputs = processor(
            audios=[chunk_waveform_1ch.squeeze(0).cpu().numpy()],
            descriptions=[prompt],
        ).to(device)
        with torch.inference_mode():
            result = model.separate(inputs, predict_spans=True)
        target_chunk = result.target[0].cpu()
        residual_chunk = result.residual[0].cpu()
        return target_chunk, residual_chunk
    except Exception:
        with tempfile.TemporaryDirectory() as tmpdir:
            chunk_path = Path(tmpdir) / "chunk.wav"
            torchaudio.save(str(chunk_path), chunk_waveform_1ch.cpu(), target_sampling_rate)
            inputs = processor(audios=[str(chunk_path)], descriptions=[prompt]).to(device)
            with torch.inference_mode():
                result = model.separate(inputs, predict_spans=True)
            target_chunk = result.target[0].cpu()
            residual_chunk = result.residual[0].cpu()
            return target_chunk, residual_chunk



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
