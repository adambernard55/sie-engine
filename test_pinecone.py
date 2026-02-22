# test_pinecone.py
from pinecone import Pinecone
import os
from dotenv import load_dotenv

load_dotenv()

# Test connection
pc = Pinecone(api_key=os.getenv("PINECONE_API_KEY"))
index = pc.Index(os.getenv("PINECONE_INDEX_NAME"))

# Get index stats
stats = index.describe_index_stats()

print(f"✓ Connected to Pinecone")
print(f"Index: {os.getenv('PINECONE_INDEX_NAME')}")
print(f"Total vectors: {stats['total_vector_count']}")
print(f"Dimensions: {stats['dimension']}")
print(f"Embedding Model: text-embedding-3-small")