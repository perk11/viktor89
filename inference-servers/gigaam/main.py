import argparse
import os
import shutil
import tempfile
from pathlib import Path
from typing import Any

import gigaam
import uvicorn
from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import JSONResponse

DEFAULT_MODEL_NAME = "v3_e2e_rnnt"

app = FastAPI(title="GigaAM whisper.cpp-compatible server")

loaded_model = None
loaded_model_name = None
runtime_model_name = DEFAULT_MODEL_NAME


def get_model() -> Any:
    global loaded_model
    global loaded_model_name

    if loaded_model is None or loaded_model_name != runtime_model_name:
        loaded_model = gigaam.load_model(runtime_model_name)
        loaded_model_name = runtime_model_name

    return loaded_model


def extract_text_from_transcription(transcription_result: Any) -> str:
    if isinstance(transcription_result, str):
        return transcription_result.strip()

    if transcription_result is None:
        return ""

    if isinstance(transcription_result, list):
        collected_segments = []
        for segment in transcription_result:
            segment_text = getattr(segment, "text", None)
            if segment_text:
                collected_segments.append(str(segment_text).strip())
        return " ".join(part for part in collected_segments if part).strip()

    direct_text = getattr(transcription_result, "text", None)
    if direct_text:
        return str(direct_text).strip()

    return str(transcription_result).strip()


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok", "model": runtime_model_name}


@app.post("/inference")
async def inference(file: UploadFile = File(...)) -> JSONResponse:
    suffix = Path(file.filename or "audio.wav").suffix or ".wav"
    temporary_directory_path = tempfile.mkdtemp(prefix="gigaam-server-")
    temporary_audio_file_path = os.path.join(temporary_directory_path, f"input{suffix}")

    try:
        with open(temporary_audio_file_path, "wb") as temporary_audio_file:
            shutil.copyfileobj(file.file, temporary_audio_file)

        model = get_model()

        try:
            transcription_result = model.transcribe_longform(temporary_audio_file_path)
        except Exception:
            transcription_result = model.transcribe(temporary_audio_file_path)

        transcription_text = extract_text_from_transcription(transcription_result)
        return JSONResponse(content={"text": transcription_text})
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"transcription failed: {exc}") from exc
    finally:
        try:
            file.file.close()
        except Exception:
            pass
        shutil.rmtree(temporary_directory_path, ignore_errors=True)


def parse_command_line_arguments() -> argparse.Namespace:
    command_line_parser = argparse.ArgumentParser()
    command_line_parser.add_argument("--port", type=int, required=True)
    command_line_parser.add_argument("--model", default=DEFAULT_MODEL_NAME)
    return command_line_parser.parse_args()


if __name__ == "__main__":
    parsed_arguments = parse_command_line_arguments()
    runtime_model_name = parsed_arguments.model
    uvicorn.run(app, port=parsed_arguments.port)
