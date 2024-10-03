import argparse
import pickle
from llama_index.core import VectorStoreIndex, SimpleDirectoryReader, Settings
from llama_index.embeddings.huggingface import HuggingFaceEmbedding

parser = argparse.ArgumentParser(description="llama-index index builder")
parser.add_argument('documents_dir', type=str, help='Path to directory with documents')
parser.add_argument('index_file_path', type=str, help='Path where to save index')
args = parser.parse_args()
Settings.embed_model = HuggingFaceEmbedding(model_name="BAAI/bge-m3")
documents = SimpleDirectoryReader(args.documents_dir, recursive=True).load_data()

index = VectorStoreIndex.from_documents(
    documents,
)
with open(args.index_file_path, 'wb') as f:
    pickle.dump(index, f)
