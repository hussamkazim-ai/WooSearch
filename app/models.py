from pydantic import BaseModel
from typing import List, Optional

class Product(BaseModel):
    id: int
    title: str
    description: str

class SearchRequest(BaseModel):
    query: str

class SearchResponse(BaseModel):
    ids: List[int]

class RecommendRequest(BaseModel):
    positives: Optional[List[int]] = None
    negatives: Optional[List[int]] = None

class RecommendResponse(BaseModel):
    ids: List[int]