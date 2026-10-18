from pydantic_settings import BaseSettings

class Settings(BaseSettings):
    # LLM Provider
    # openai_base_url: str
    # openai_api_key: str
    # openai_model: str

    # Woo Commerce
    url: str
    consumer_key: str
    consumer_secret: str
    version: str

    # RAG
    collection_name: str
    score_threshold: float
    
settings = Settings()
