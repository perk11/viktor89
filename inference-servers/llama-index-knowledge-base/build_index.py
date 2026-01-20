import argparse
import os
import pickle

from llama_index.core import VectorStoreIndex, SimpleDirectoryReader, Settings
from llama_index.embeddings.huggingface import HuggingFaceEmbedding


parser = argparse.ArgumentParser(description="llama-index index builder")
parser.add_argument("documents_dir", type=str, help="Path to directory with documents")
parser.add_argument("index_file_path", type=str, help="Path where to save index")
parser.add_argument("base_url", type=str, help="Path where to link to")
args = parser.parse_args()

Settings.embed_model = HuggingFaceEmbedding(model_name="BAAI/bge-m3")

documents = SimpleDirectoryReader(args.documents_dir, recursive=True).load_data()

documents_dir_abs = os.path.abspath(args.documents_dir)

for document in documents:
    file_path = document.metadata.get("file_path") or document.metadata.get("filepath") or document.metadata.get("path")
    if not isinstance(file_path, str) or not file_path:
        continue

    file_path_abs = os.path.abspath(file_path)

    try:
        relative_path = os.path.relpath(file_path_abs, start=documents_dir_abs)
    except ValueError:
        continue

    relative_url_path = relative_path.replace(os.sep, "/").lstrip("/")
    public_url = f"{args.base_url}/{relative_url_path}"

    document.metadata["url"] = public_url
    document.metadata.setdefault("title", os.path.basename(file_path_abs))

index = VectorStoreIndex.from_documents(documents)

with open(args.index_file_path, "wb") as f:
    pickle.dump(index, f)
