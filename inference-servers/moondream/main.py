import argparse
import base64
import io
import time
import uuid
from typing import Optional, List, Union, Literal

import requests
import torch
from fastapi import FastAPI
from PIL import Image
from pydantic import BaseModel, Field, model_validator
from transformers import AutoModelForCausalLM, AutoTokenizer

parser = argparse.ArgumentParser(description="OpenAI-compatible chat server (Moondream3, with image support)")
parser.add_argument("--port", type=int, required=True, help="Port to listen on")
args = parser.parse_args()

moondream = AutoModelForCausalLM.from_pretrained(
    "moondream/moondream3-preview",
    trust_remote_code=True,
    dtype=torch.bfloat16,
    device_map={"": "cuda"},
)
moondream.compile()

# --------- OpenAI-compatible schemas (multimodal) ---------
class ImageURL(BaseModel):
    url: str
    detail: Optional[Literal["auto", "low", "high"]] = "auto"


class ContentPart(BaseModel):
    type: Literal["text", "image_url"]
    text: Optional[str] = None
    image_url: Optional[Union[str, ImageURL]] = None

    @model_validator(mode="after")
    def validate_by_type(self):
        if self.type == "image_url" and self.image_url is None:
            raise ValueError("image_url part requires 'image_url'")
        return self


class Message(BaseModel):
    role: Literal["system", "user", "assistant"]
    content: Union[str, List[ContentPart]]


class ChatCompletionRequest(BaseModel):
    model: Optional[str] = Field(default="moondream/moondream3-preview")
    messages: List[Message]
    stream: Optional[bool] = False
    temperature: Optional[float] = 0.2
    max_tokens: Optional[int] = 256


app = FastAPI()

def _is_data_url(url: str) -> bool:
    return url.startswith("data:") and ";base64," in url

def _load_pil_from_data_url(url: str) -> Image.Image:
    header, b64data = url.split(";base64,", 1)
    image_bytes = base64.b64decode(b64data)
    return Image.open(io.BytesIO(image_bytes)).convert("RGB")

def _load_pil_from_http(url: str) -> Image.Image:
    resp = requests.get(url, timeout=20)
    resp.raise_for_status()
    return Image.open(io.BytesIO(resp.content)).convert("RGB")

def _coerce_image_url(value: Union[str, ImageURL]) -> str:
    return value if isinstance(value, str) else value.url

def _get_response_text_from_chat_messages(messages: List[Message]) -> Optional[str]:
    text: Optional[str] = None
    image: Optional[Image.Image] = None

    for message in messages:
        if message.role == 'system':
            continue #Skip system prompt
        else:
            if isinstance(message.content, str):
                text = message.content
                continue

            text_encountered = False
            for part in message.content:
                if part.type == "text" and part.text:
                    if not text_encountered:
                        text = ''
                        text_encountered = True
                    text = text + part.text
                elif part.type == "image_url" and part.image_url:
                    url = _coerce_image_url(part.image_url)
                    image = _load_pil_from_data_url(url) if _is_data_url(url) else _load_pil_from_http(url)
    print(f"Sending query to moondream: {text}")
    result = moondream.query(image=image, question=text)
    return result['answer']

@app.post("/v1/chat/completions")
async def chat_completions(request: ChatCompletionRequest):
    if not request.messages:
        resp_content = "No messages to respond to."
    else:
        try:
            resp_content = _get_response_text_from_chat_messages(request.messages)
        except Exception as e:
            resp_content = f"Error while processing request: {e}"

    return {
        "id": f"chatcmpl-{uuid.uuid4()}",
        "object": "chat.completion",
        "created": int(time.time()),
        "model": request.model or "moondream/moondream3-preview",
        "choices": [
            {
                "index": 0,
                "message": {"role": "assistant", "content": resp_content},
                "finish_reason": "stop",
            }
        ],
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=args.port)
