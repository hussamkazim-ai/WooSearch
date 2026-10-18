# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A RAG-based product search engine for WooCommerce stores. Two halves that must run together:

1. **FastAPI backend** (Python, this directory) — pulls products from the WooCommerce REST API, embeds them into Qdrant, and serves hybrid (dense + sparse) search.
2. **WordPress plugin** (`woo-search-integration.php`) — replaces the storefront's native product search with calls to the backend. Shipped as `woo-search-integration.zip` for install on the WP site.

## Commands

Environment is the conda env `rag` (`/home/hussam/anaconda3/envs/rag`). Everything is installed except `woocommerce`, which the backend needs:

```bash
conda activate rag
pip install woocommerce          # only missing dependency
python main.py                   # uvicorn on 127.0.0.1:8000, reload=True
```

Qdrant must already be running locally — the client URL is hardcoded, not configurable:

```bash
curl http://127.0.0.1:6333/collections    # verify; collection is "WooSearch"
```

There is no test suite, linter config, or requirements file. If you add dependencies, say so explicitly rather than assuming an existing manifest.

### Exercising the API

```bash
curl -X GET  http://127.0.0.1:8000/api/bulk-import      # re-index every published product
curl -X POST http://127.0.0.1:8000/api/search \
     -H 'Content-Type: application/json' -d '{"query":"shoes"}'
```

## Backend architecture

**Startup wiring.** `main.py` uses a FastAPI `lifespan` to call `load_rag_resources()` once and stash the result on `app.state.rag_resources`. The model loading and Qdrant connection are expensive, so nothing else in the codebase constructs them — route handlers retrieve the bundle via `request.app.state.rag_resources` and pass it down as the first argument to everything in `rag.py`. Keep that pattern: `rag.py` functions are deliberately pure w.r.t. module state and take `RagResources` explicitly.

**Hybrid retrieval in one collection.** `WooSearch` holds two named vectors, `dense` (FastEmbedEmbeddings, 384-dim, cosine) and `sparse` (Qdrant/bm25). `load_qdrant()` creates the collection on first use with the correct vector/slot config; if you change embedding model or dimensions, the existing collection must be dropped, since the create is skipped when the collection already exists. Embedding caches live at `~/.cache/fastembed`.

**Two vector store objects, one collection.** `load_dense_vector_store()` and `load_sparse_vector_store()` each return their own `QdrantVectorStore` over the same collection, differing only in `retrieval_mode`. `QdrantVectorStore` requires the retrieval mode to match the vector slot you query, which is why search goes through two stores rather than one.

**Write path.** `store_in_qdrant()` builds the payload by hand rather than going through the vector store, computing both embeddings and upserting a single `PointStruct`. The point ID is the WooCommerce `product.id`, so re-running bulk-import overwrites rather than duplicates — indexing is idempotent by construction.

**Ingestion.** `import_products_in_bulk()` pages through `products` with `status=publish` until an empty page, and strips `<p>` / `</p>\n` from descriptions with plain `str.replace` (not an HTML parser).

## Current state / known gaps

`POST /api/search` returns a **bare JSON array of WooCommerce product IDs** — `search_products()` unions the dense and sparse result ID sets and returns `list(...)`, and the route returns that directly. There is no ranking or relevance score in the response, and the union means dense and sparse cannot be weighted against each other.

The WordPress plugin has a hardcoded `https://woocom.loca.lt/api/search` URL in **two separate places** (the top-of-file `$woo_search_api_url` and inside `handle_woo_search()`), and `handle_woo_search()` re-declares it locally instead of reusing the first. Change both when the tunnel moves. A localtunnel/public URL is required because the WP site is served from `woo.local` and cannot reach the backend's loopback address.

## WordPress plugin contract

The plugin's browser JS posts to `admin-ajax.php`, not to the API directly, so the localized `endpoint` value is unused — the real URL lives server-side in `handle_woo_search()`. That PHP handler proxies to the FastAPI `/api/search` and then normalizes the response into a list of product IDs, tolerating any of: a bare array of ints, or an object with an `ids`, `products`, or `items` key. It then hydrates those IDs with `wc_get_products()` and returns `{id, name, url, image, price_html}` for client-side rendering. When you make `/api/search` return real data, returning `{"ids": [...]}` is the most direct fit.

`wp-block-search` and `form[role="search"]` are both in the bind selector because the theme (`salecraft-ecommerce`, a block theme) renders its search as a core Search block, whose form carries only `wp-block-search`-prefixed classes — the `woocommerce-product-search` / `search-form` classes the plugin originally targeted never appear. When changing themes, re-check which form markup is actually emitted and extend the selector; a selector that matches nothing fails silently, and the storefront just falls back to native search with no error anywhere.

`pre_get_posts` sets `post__in => [0]` for product searches, forcing the theme's own search template to render no results so the plugin's JS is the only thing showing products. This depends on the search form carrying `<input type="hidden" name="post_type" value="product">`, which the Search block above does emit — a theme or widget whose form omits that field will bypass the override entirely.

The plugin has no `Version:` header, so WordPress cannot differentiate builds. Add one if you need uploads to replace an existing install cleanly.

## Security note

The git root is `/home/hussam/Desktop`, and **`.env` is not gitignored** while the repo has no commits yet. `.env` contains a live OpenRouter API key and WooCommerce `ck_`/`cs_` credentials. Add a `.gitignore` covering `.env`, `__pycache__/`, and `*.zip` before the first commit.
