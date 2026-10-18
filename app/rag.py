from langchain_community.embeddings import FastEmbedEmbeddings
from langchain_qdrant import QdrantVectorStore, RetrievalMode
from langchain_qdrant.fastembed_sparse import FastEmbedSparse
from qdrant_client import QdrantClient, models
from qdrant_client.models import (
    VectorParams, 
    Distance, 
    SparseVectorParams, 
    SparseIndexParams, 
    SparseVector,
    PointStruct,
)
from dataclasses import dataclass
from app.config import settings
from app.models import Product
import os
import pprint
import re
import html

# ----------------------------------------------------------------------------------------------------
# Startup Tasks
# ----------------------------------------------------------------------------------------------------
def load_embedding():
    cache_path = os.path.expanduser("~/.cache/fastembed")
    embedding = FastEmbedEmbeddings(cache_dir=cache_path)
    return embedding

def load_sparse_embedding():
    cache_path = os.path.expanduser("~/.cache/fastembed")
    sparse_embedding = FastEmbedSparse(model_name="Qdrant/bm25", cache_dir=cache_path)
    return sparse_embedding

def load_qdrant():
    qdrant_client = QdrantClient(path="./qdrant_storage")

    if not qdrant_client.collection_exists(settings.collection_name): # Creating the collection if it doesn't exist
        qdrant_client.create_collection(
            collection_name=settings.collection_name,
            vectors_config={
                "dense": VectorParams(
                    size=384,
                    distance=Distance.COSINE
                ),
            },
            sparse_vectors_config={
                "sparse": SparseVectorParams(
                    index=SparseIndexParams(
                        on_disk=False
                    )
                )
            },
        )

    return qdrant_client

def load_dense_vector_store(client: QdrantClient):
    dense_vector_store = QdrantVectorStore(
        client=client,
        embedding=load_embedding(),
        collection_name=settings.collection_name,
        retrieval_mode=RetrievalMode.DENSE,
        vector_name="dense"
    )
    return dense_vector_store

def load_sparse_vector_store(client: QdrantClient):
    sparse_embedding = load_sparse_embedding()
    sparse_vector_store = QdrantVectorStore(
        client=client,
        sparse_embedding=sparse_embedding,
        collection_name=settings.collection_name,
        retrieval_mode=RetrievalMode.SPARSE,
        sparse_vector_name="sparse"
    )
    return sparse_vector_store

@dataclass
class RagResources:
    embedding: FastEmbedEmbeddings
    sparse_embedding: FastEmbedSparse
    qdrant_client: QdrantClient
    dense_vector_store: QdrantVectorStore
    sparse_vector_store: QdrantVectorStore

qdrant_client = load_qdrant()

def load_rag_resources():
    return RagResources(
        embedding=load_embedding(),
        sparse_embedding=load_sparse_embedding(),
        qdrant_client=qdrant_client,
        dense_vector_store=load_dense_vector_store(qdrant_client),
        sparse_vector_store=load_sparse_vector_store(qdrant_client)
    )

# ----------------------------------------------------------------------------------------------------
# RAG Chain
# ----------------------------------------------------------------------------------------------------
def store_in_qdrant(rag_resources: RagResources, product: Product):
    client = rag_resources.qdrant_client
    embedding = rag_resources.embedding
    sparse_embedding = rag_resources.sparse_embedding

    product_text = f"{product.title}\n\n{product.description}"
    product_text = re.sub(r"<[^>]+>", "", html.unescape(product_text))
    dense_vector = embedding.embed_query(product_text)
    sparse_vector = sparse_embedding.embed_query(product_text)

    point = PointStruct(
        id=product.id,
        vector={
            "dense": dense_vector,
            "sparse": SparseVector(
                indices=sparse_vector.indices,
                values=sparse_vector.values
            )
        },
        payload={
            "page_content": product_text,
            "metadata": {
                "product_id": product.id,
                "title": product.title,
                "description": product.description
            }
        }
    )

    client.upsert(
        collection_name=settings.collection_name,
        points=[point]
    )

def search_products(rag_resources: RagResources, query):
    dense_vector_store = rag_resources.dense_vector_store
    sparse_vector_store = rag_resources.sparse_vector_store

    dense_docs = dense_vector_store.similarity_search(query, score_threshold=settings.score_threshold) # Returning results with 40%+ match
    sparse_docs = sparse_vector_store.similarity_search(query)

    pprint.pprint(sparse_docs)

    dense_ids = [doc.metadata["product_id"] for doc in dense_docs]
    sparse_ids = [doc.metadata["product_id"] for doc in sparse_docs]

    results = list(set(sparse_ids + dense_ids)) # Removing Duplicates

    return results

def recommend_products(rag_resources: RagResources, positives, negatives):

    if not positives and not negatives:
        return []

    try: 
        client = rag_resources.qdrant_client
        results = client.query_points(
            collection_name=settings.collection_name,
            query=models.RecommendQuery(
                recommend=models.RecommendInput(
                    positive=positives,
                    negative=negatives
                )
            ),
            using="dense"
        )

        return[point.id for point in results.points]

    except Exception as e:
        raise e
    