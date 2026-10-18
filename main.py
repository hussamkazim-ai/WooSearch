from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from contextlib import asynccontextmanager
from app.routes import router
from app.rag import load_rag_resources
from app.config import settings

# -------------------------------------------------------------------------------------------------------------------
# Creating lifespan function to load heavy things at startup
# -------------------------------------------------------------------------------------------------------------------
@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup Tasks
    app.state.rag_resources = load_rag_resources()
    
    yield
    # Shutdown Tasks

# -------------------------------------------------------------------------------------------------------------------
# Creating the FastAPI app
# -------------------------------------------------------------------------------------------------------------------
app = FastAPI(
    title="WooCommerce Search Engine",
    description="Create RAG based search engines for WooCommerce Stores",
    version="1.0.0",
    lifespan=lifespan
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=[settings.url],
    allow_methods=["*"],
    allow_headers=["*"]
)

app.include_router(router)

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("main:app", host="127.0.0.1", port=8000)