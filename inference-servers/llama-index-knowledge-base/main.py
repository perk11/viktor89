import argparse
import pickle
import time
from typing import Optional, List

from fastapi import FastAPI
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

app = FastAPI()
with open(args.index, 'rb') as f:
    index = pickle.load(f)


class Message(BaseModel):
    role: str
    content: str


class ChatCompletionRequest(BaseModel):
    messages: List[Message]
    stream: Optional[bool] = False


def messages_to_chat_history(messages: List[Message]) -> List[ChatMessage]:
    chat_history = []
    for message in messages:
        if message.role == "user":
            role = MessageRole.USER
        elif message.role == "system":
            role = MessageRole.SYSTEM
        else:
            role = MessageRole.ASSISTANT
        chat_message = ChatMessage(content=message.content, role=role)
        chat_history.append(chat_message)
    return chat_history


@app.post("/v1/chat/completions")
async def chat_completions(request: ChatCompletionRequest):
    chat_engine = index.as_chat_engine(
        chat_mode="condense_question",
        chat_history=messages_to_chat_history(request.messages),
    )
    if request.messages:
        resp_content = chat_engine.chat(request.messages[-1].content).response
    else:
        resp_content = "Я не получил от вас никаких сообщений"

    return {
        "id": "1337",
        "object": "chat.completion",
        "created": time.time(),
        "choices": [{"message": Message(role="assistant", content=resp_content)}],
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="localhost", port=args.port)
