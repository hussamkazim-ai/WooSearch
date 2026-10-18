from fastapi.requests import Request
from app.bulk_import import import_products_in_bulk
from app.rag import store_in_qdrant, search_products, recommend_products
from app.models import SearchRequest, SearchResponse, Product, RecommendRequest, RecommendResponse
from app.config import settings

async def import_bulk_products(request: Request):
    """Imports products from WooCommerce in bulk using WooCommerce's v3 api endpoint and stores all the published products in the vector store."""
    rag_resources=request.app.state.rag_resources
    products = await import_products_in_bulk()
    for product in products:
        store_in_qdrant(rag_resources, product)

async def search(request: Request, body: SearchRequest):
    """Finds the related products to user query and returns the ids of the matching products"""
    rag_resources=request.app.state.rag_resources
    results = search_products(rag_resources, body.query)
    return SearchResponse(ids=results)
    
async def recommend(request: Request, recommend_request: RecommendRequest):
    rag_resources=request.app.state.rag_resources
    positives = recommend_request.positives
    negatives = recommend_request.negatives
    ids = recommend_products(rag_resources, positives, negatives)
    return RecommendResponse(ids=ids)

async def create_product(request: Request):
    """Creates a new product in the vector store when the WooCommerce webhook sends a request"""
    rag_resources=request.app.state.rag_resources
    payload = await request.json()
    if payload.get("status") == "publish":
        product = Product(id=payload["id"], title=payload["name"], description=payload["description"])
        store_in_qdrant(rag_resources, product)

async def update_product(request: Request):
    """Updates a product in the vector store when the WooCommerce webhook sends a request"""
    rag_resources=request.app.state.rag_resources
    payload = await request.json()

    if payload.get("status") == "publish": # Updating the product if it's status is publish
        product = Product(id=payload["id"], title=payload["name"], description=payload["description"])
        store_in_qdrant(rag_resources, product)

    else: # Deleting the product it it's status is updated to draft
        id = payload["id"]
        rag_resources.qdrant_client.delete(
            collection_name=settings.collection_name,
            points_selector=[id]
        )

async def delete_product(request: Request):
    """Deletes the product from vector store"""
    rag_resources=request.app.state.rag_resources
    payload = await request.json()
    id = payload["id"]
    rag_resources.qdrant_client.delete(
        collection_name=settings.collection_name,
        points_selector=[id]
    )