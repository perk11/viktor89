import argparse
import base64
import io
import time
import uuid
import json
from typing import Optional, List, Union, Literal, Dict, Any

import requests
import torch
from fastapi import FastAPI
from PIL import Image
from pydantic import BaseModel, Field, model_validator
from transformers import Qwen3VLForConditionalGeneration, AutoProcessor

parser = argparse.ArgumentParser(description="OpenAI-compatible chat server (Qwen3-VL-8B, with image support)")
parser.add_argument("--port", type=int, required=True, help="Port to listen on")
parser.add_argument("--model", type=str, required=True, help="Model to use")
args = parser.parse_args()

QWEN_MODEL_NAME = args.model

qwen_model = Qwen3VLForConditionalGeneration.from_pretrained(
    QWEN_MODEL_NAME,
    dtype=torch.bfloat16,
    attn_implementation="flash_attention_2",
    device_map="auto",
)
qwen_processor = AutoProcessor.from_pretrained(QWEN_MODEL_NAME)

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
    model: Optional[str] = Field(default=QWEN_MODEL_NAME)
    messages: List[Message]
    stream: Optional[bool] = False
    temperature: Optional[float] = 0.2
    max_tokens: Optional[int] = 4096


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

def _openai_messages_to_qwen_messages(messages: List[Message]) -> List[Dict[str, Any]]:
    qwen_messages: List[Dict[str, Any]] = []

    for message in messages:
        # System messages are preserved so the model can use them as context.
        content_items: List[Dict[str, Any]] = []

        if isinstance(message.content, str):
            text_value = message.content
            if text_value is not None and text_value != "":
                content_items.append({"type": "text", "text": text_value})
        else:
            for part in message.content:
                if part.type == "text" and part.text:
                    content_items.append({"type": "text", "text": part.text})
                elif part.type == "image_url" and part.image_url:
                    url = _coerce_image_url(part.image_url)
                    if _is_data_url(url):
                        # Data URLs are decoded to PIL to guarantee local availability.
                        pil_image = _load_pil_from_data_url(url)
                        content_items.append({"type": "image", "image": pil_image})
                    else:
                        # HTTP(S) URLs are passed through so the processor can handle them.
                        content_items.append({"type": "image", "url": url})

        # Ensure each message has content so templates remain well-formed.
        if not content_items:
            content_items = [{"type": "text", "text": ""}]

        qwen_messages.append({"role": message.role, "content": content_items})

    return qwen_messages

def _generate_qwen_response_from_messages(
        messages: List[Message],
        temperature: Optional[float],
        max_new_tokens: Optional[int],
) -> str:
    qwen_chat_messages = _openai_messages_to_qwen_messages(messages)

    # apply_chat_template constructs inputs and, for VL, also prepares vision features when present.
    inputs = qwen_processor.apply_chat_template(
        qwen_chat_messages,
        tokenize=True,
        add_generation_prompt=True,
        return_dict=True,
        return_tensors="pt",
    )

    # Some configs include token_type_ids; they are not required for generation.
    inputs.pop("token_type_ids", None)

    primary_device = next(qwen_model.parameters()).device
    for key, tensor in list(inputs.items()):
        if hasattr(tensor, "to"):
            inputs[key] = tensor.to(primary_device)

    generation_kwargs: Dict[str, Any] = {"max_new_tokens": max_new_tokens or 32768}
    if temperature is not None and temperature > 0:
        generation_kwargs.update({"do_sample": True, "temperature": float(temperature)})

    user_query_preview = "max_new_tokens: " + str(max_new_tokens)
    for msg in qwen_chat_messages:
        for part in msg["content"]:
            if part["type"] == "text":
                user_query_preview += "[" + json.dumps(part) + "],"
            else:
                user_query_preview += "[" + part["type"] + "],"
    print(f"Sending query to {QWEN_MODEL_NAME}: {user_query_preview[:2000]}")

    with torch.inference_mode():
        generated_ids = qwen_model.generate(**inputs, **generation_kwargs)

    prompt_token_count = inputs["input_ids"].shape[-1]
    continuation_token_ids = generated_ids[:, prompt_token_count:]

    decoded_texts = qwen_processor.batch_decode(
        continuation_token_ids, skip_special_tokens=True, clean_up_tokenization_spaces=False
    )
    return decoded_texts[0] if len(decoded_texts) == 1 else "\n".join(decoded_texts)

def _get_response_text_from_chat_messages(messages: List[Message], temperature: Optional[float], max_new_tokens: Optional[int]) -> Optional[str]:

    result =  _generate_qwen_response_from_messages(messages, temperature=temperature, max_new_tokens=max_new_tokens)
    if "</think>" in result:
        result = "<think>" + result
    return result

@app.post("/v1/chat/completions")
async def chat_completions(request: ChatCompletionRequest):
    if not request.messages:
        resp_content = "No messages to respond to."
    else:
        try:
            resp_content = _get_response_text_from_chat_messages(
                request.messages,
                temperature=request.temperature,
                max_new_tokens=request.max_tokens,
            )
            print("Response content:", resp_content)
        except Exception as e:
            resp_content = f"Error while processing request"
            print(f"Error while processing request: {e}")


    return {
        "id": f"chatcmpl-{uuid.uuid4()}",
        "object": "chat.completion",
        "created": int(time.time()),
        "model": request.model or QWEN_MODEL_NAME,
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
