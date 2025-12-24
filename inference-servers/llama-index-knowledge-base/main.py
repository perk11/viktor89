import argparse
import pickle
import time
from typing import Optional, List, Union, Literal, Any
import logging
import sys
from fastapi import FastAPI, HTTPException
from llama_index.core import Settings
from llama_index.core.base.llms.types import ChatMessage, MessageRole
from llama_index.embeddings.huggingface import HuggingFaceEmbedding
from llama_index.llms.openai import OpenAI
from pydantic import BaseModel

parser = argparse.ArgumentParser(description="llama-index OpenAI-compatible server")
parser.add_argument('--port', type=int, help='port to listen on', required=True)
parser.add_argument('--llm-port', type=int, help='OpenAI-compatible LLM port to connect to', required=True)
parser.add_argument('--index', type=str, help='llama-index pickle index path', required=True)

args = parser.parse_args()
Settings.embed_model = HuggingFaceEmbedding(model_name="BAAI/bge-m3")
Settings.llm = OpenAI(api_key="BAD_KEY", api_base="http://localhost:" + str(args.llm_port), timeout=999999)
logging.basicConfig(stream=sys.stdout, level=logging.DEBUG)
logging.getLogger().addHandler(logging.StreamHandler(stream=sys.stdout))
app = FastAPI()
with open(args.index, "rb") as file_handle:
    index = pickle.load(file_handle)


class ContentPartText(BaseModel):
    type: Literal["text"]
    text: str


class ContentPartImageUrl(BaseModel):
    type: Literal["image_url"]
    image_url: dict


ContentPart = Union[ContentPartText, ContentPartImageUrl]


class Message(BaseModel):
    role: str
    # Supports both legacy string content and modern "content parts" arrays
    content: Union[str, List[ContentPart], List[dict], None] = None


class ChatCompletionRequest(BaseModel):
    messages: List[Message]
    stream: Optional[bool] = False


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
            if isinstance(part, dict):
                if part.get("type") == "text" and isinstance(part.get("text"), str):
                    text_chunks.append(part["text"])
                    continue
            # ignore non-text parts (e.g., image_url)
        return "".join(text_chunks)
    return ""


def role_to_llama_index_role(role: str) -> MessageRole:
    if role == "user":
        return MessageRole.USER
    if role == "system":
        return MessageRole.SYSTEM
    return MessageRole.ASSISTANT


def messages_to_chat_history(messages: List[Message]) -> List[ChatMessage]:
    chat_history: List[ChatMessage] = []
    for message in messages:
        chat_history.append(
            ChatMessage(
                content=normalize_message_content_to_text(message.content),
                role=role_to_llama_index_role(message.role),
            )
        )
    return chat_history


def find_last_user_message_text(messages: List[Message]) -> str:
    for message in reversed(messages):
        if message.role == "user":
            return normalize_message_content_to_text(message.content)
    return ""


def request_uses_chunked_content(messages: List[Message]) -> bool:
    for message in messages:
        if isinstance(message.content, list):
            return True
    return False


@app.post("/v1/chat/completions")
async def chat_completions(request: ChatCompletionRequest):
    print("got request", flush=True)

    if request.stream:
        raise HTTPException(status_code=400, detail="stream=true is not supported by this server")

    chat_engine = index.as_chat_engine(
        chat_mode="best",
        chat_history=messages_to_chat_history(request.messages),
    )

    last_user_text = find_last_user_message_text(request.messages)
    if last_user_text:
        resp_content = chat_engine.chat(last_user_text).response
    else:
        resp_content = "Я не получил от вас никаких сообщений"

    if request_uses_chunked_content(request.messages):
        assistant_message: dict = {
            "role": "assistant",
            "content": [{"type": "text", "text": resp_content}],
        }
    else:
        assistant_message = {
            "role": "assistant",
            "content": resp_content,
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
