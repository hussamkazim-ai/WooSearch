from fastapi.routing import APIRouter
from fastapi import Header, HTTPException, Depends
from app.config import settings
from app.api import (
    import_bulk_products, 
    search, 
    recommend,
    create_product, 
    update_product, 
    delete_product
)
from app.models import SearchResponse, RecommendResponse

async def verify_consumer_secret(consumer_secret: str = Header(...)): # Verifying consumer secret before every request
    expected_secret = settings.consumer_secret
    if not expected_secret or consumer_secret != expected_secret:
        raise HTTPException(403, "Invalid Consumer Secret")


router = APIRouter(prefix="/api", dependencies=[Depends(verify_consumer_secret)])

router.add_api_route("/bulk-import",         import_bulk_products,   methods=["GET"])
router.add_api_route("/search",              search,                 methods=["POST"],   response_model=SearchResponse)
router.add_api_route("/recommend",           recommend,              methods=["POST"],   response_model=RecommendResponse)
router.add_api_route("/create-product",      create_product,         methods=["POST"])
router.add_api_route("/update-product",      update_product,         methods=["POST"])
router.add_api_route("/delete-product",      delete_product,         methods=["POST"])
