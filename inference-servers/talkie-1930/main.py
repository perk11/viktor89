import argparse
import logging
import sys
import time
from typing import Any, List, Literal, Optional, Union

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from talkie import Message as TalkieMessage
from talkie import Talkie

parser = argparse.ArgumentParser(description="Talkie OpenAI-compatible server")
parser.add_argument("--port", type=int, help="port to listen on", required=True)
parser.add_argument("--model", type=str, help="Talkie model name", default="talkie-1930-13b-it")

args = parser.parse_args()

logging.basicConfig(stream=sys.stdout, level=logging.DEBUG)
logging.getLogger().addHandler(logging.StreamHandler(stream=sys.stdout))

app = FastAPI()
talkie_model = Talkie(args.model)


class ContentPartText(BaseModel):
    type: Literal["text"]
    text: str


class ContentPartImageUrl(BaseModel):
    type: Literal["image_url"]
    image_url: dict


ContentPart = Union[ContentPartText, ContentPartImageUrl]


class OpenAIStyleMessage(BaseModel):
    role: str
    content: Union[str, List[ContentPart], List[dict], None] = None


class ChatCompletionRequest(BaseModel):
    messages: List[OpenAIStyleMessage]
    stream: Optional[bool] = False
    temperature: Optional[float] = None
    max_tokens: Optional[int] = None


def normalize_message_content_to_text(message_content: Union[str, List[Any], None]) -> str:
    if message_content is None:
        return ""

    if isinstance(message_content, str):
        return message_content

    if isinstance(message_content, list):
        text_chunks: List[str] = []

        for part in message_content:
            if isinstance(part, ContentPartText):
                text_chunks.append(part.text)
                continue

            if isinstance(part, dict) and part.get("type") == "text" and isinstance(part.get("text"), str):
                text_chunks.append(part["text"])

        return "".join(text_chunks)

    return ""


def request_uses_chunked_content(messages: List[OpenAIStyleMessage]) -> bool:
    return any(isinstance(message.content, list) for message in messages)


def find_last_user_message_text(messages: List[OpenAIStyleMessage]) -> str:
    for message in reversed(messages):
        if message.role == "user":
            return normalize_message_content_to_text(message.content)
    return ""


def openai_messages_to_talkie_messages(messages: List[OpenAIStyleMessage]) -> List[TalkieMessage]:
    talkie_messages: List[TalkieMessage] = []

    for message in messages:
        if message.role == "system":
            continue

        normalized_text = normalize_message_content_to_text(message.content)
        talkie_messages.append(TalkieMessage(role=message.role, content=normalized_text))

    return talkie_messages


@app.post("/v1/chat/completions")
async def chat_completions(request: ChatCompletionRequest):
    print("got request", flush=True)

    if request.stream:
        raise HTTPException(status_code=400, detail="stream=true is not supported by this server")

    last_user_text = find_last_user_message_text(request.messages)
    if not last_user_text:
        response_text = "Please provide a message to start the conversation."
    else:
        talkie_messages = openai_messages_to_talkie_messages(request.messages)
        talkie_kwargs = {}

        if request.temperature is not None:
            talkie_kwargs["temperature"] = request.temperature

        if request.max_tokens is not None:
            talkie_kwargs["max_tokens"] = request.max_tokens

        talkie_result = talkie_model.chat(talkie_messages, **talkie_kwargs)
        response_text = getattr(talkie_result, "text", "")
        if not isinstance(response_text, str):
            response_text = str(response_text) if response_text is not None else ""

    if request_uses_chunked_content(request.messages):
        assistant_message: dict = {
            "role": "assistant",
            "content": [{"type": "text", "text": response_text}],
        }
    else:
        assistant_message = {
            "role": "assistant",
            "content": response_text,
        }

    return {
        "id": "1337",
        "object": "chat.completion",
        "created": int(time.time()),
        "choices": [{"index": 0, "message": assistant_message, "finish_reason": "stop"}],
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="localhost", port=args.port)
