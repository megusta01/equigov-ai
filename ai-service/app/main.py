"""Serviço de IA do EquiGov: ingestão de documentos, busca e geração de planos de ação fundamentados.

Uso interno: só o WordPress fala com ele, autenticado por token de serviço (X-Service-Token).
Documentação interativa em /docs (OpenAPI).
"""

from __future__ import annotations

import hmac
import logging
import time
import uuid
from dataclasses import dataclass

from fastapi import Depends, FastAPI, File, Form, HTTPException, Request, Security, UploadFile
from fastapi.responses import JSONResponse
from fastapi.security import APIKeyHeader

from . import __version__
from .api.schemas import PlanRequest, SearchRequest
from .config import Settings
from .generation.llm import FakeLLM, LLMClient, LLMError, OpenAICompatibleClient
from .generation.planner import Planner
from .ingestion.extract import SUPPORTED, ExtractionError
from .ingestion.service import IngestionService
from .logging_setup import configure
from .retrieval.embeddings import Embedder, load_embedder
from .retrieval.retriever import Retriever
from .retrieval.store import Store

log = logging.getLogger("equigov.ai")

# Declarado como esquema de segurança: o /docs ganha o botão "Authorize".
_token_header = APIKeyHeader(name="X-Service-Token", auto_error=False, description="Token compartilhado com o WordPress")


@dataclass
class Container:
    settings: Settings
    store: Store
    retriever: Retriever
    ingestion: IngestionService
    planner: Planner | None
    llm_error: str | None


def build_llm(s: Settings) -> LLMClient:
    if s.llm_provider == "fake":
        return FakeLLM()
    return OpenAICompatibleClient(
        s.llm_base_url,
        s.llm_api_key,
        s.llm_model,
        timeout_s=s.llm_timeout_s,
        max_retries=s.llm_max_retries,
        temperature=s.llm_temperature,
    )


def create_app(
    settings: Settings | None = None, *, llm: LLMClient | None = None, embedder: Embedder | None = None, store: Store | None = None
) -> FastAPI:
    s = settings or Settings()
    s.validate()
    store = store or Store(s.db_path)
    if embedder is None:
        embedder = load_embedder(s.retrieval_mode, s.embedding_model)
    retriever = Retriever(store, embedder)

    planner, llm_error = None, None
    try:
        planner = Planner(s, retriever, llm or build_llm(s), store)
    except LLMError as e:  # sem chave: o serviço sobe (ingestão e busca funcionam) e o /health avisa
        llm_error = e.code

    c = Container(s, store, retriever, IngestionService(store, retriever, embedder), planner, llm_error)
    app = FastAPI(title="EquiGov AI Service", version=__version__, description=__doc__)
    app.state.c = c

    @app.middleware("http")
    async def request_log(request: Request, call_next):
        rid = request.headers.get("x-request-id") or uuid.uuid4().hex[:12]
        started = time.monotonic()
        response = await call_next(request)
        response.headers["x-request-id"] = rid
        if request.url.path != "/health":
            log.info(
                "request",
                extra={
                    "request_id": rid,
                    "method": request.method,
                    "path": request.url.path,
                    "status": response.status_code,
                    "ms": int((time.monotonic() - started) * 1000),
                },
            )
        return response

    @app.exception_handler(LLMError)
    async def llm_error_handler(_: Request, e: LLMError):
        log.warning("llm_error", extra={"code": e.code, "detail": str(e)})
        return JSONResponse({"code": e.code, "message": str(e)}, status_code=e.status)

    def require_token(token: str | None = Security(_token_header)) -> None:
        # compare_digest: tempo constante, não vaza quantos caracteres coincidem (timing attack)
        if not hmac.compare_digest((token or "").encode(), s.service_token.encode()):
            raise HTTPException(401, {"code": "unauthorized", "message": "Token de serviço inválido."})

    auth = [Depends(require_token)]

    @app.get("/health")
    def health():
        stats = store.stats()
        checks = {
            "database": True,
            "llm": c.llm_error is None,
            "retrieval_mode_configured": s.retrieval_mode,
            "retrieval_mode_effective": retriever.mode,
        }
        degraded = c.llm_error is not None or (s.retrieval_mode == "hybrid" and retriever.mode != "hybrid" and stats["chunks"] > 0)
        return {
            "status": "degraded" if degraded else "ok",
            "version": __version__,
            "checks": checks,
            "llm": {
                "provider": c.planner.llm.provider if c.planner else s.llm_provider,
                "model": c.planner.llm.model if c.planner else s.llm_model,
                "error": c.llm_error,
            },
            "library": stats,
        }

    @app.get("/v1/documents", dependencies=auth)
    def list_documents():
        return store.list_documents()

    @app.post("/v1/documents", dependencies=auth, status_code=201)
    async def upload(
        file: UploadFile = File(...), title: str | None = Form(None, max_length=300), source_url: str | None = Form(None, max_length=500)
    ):
        if source_url and not source_url.startswith(("https://", "http://")):
            raise HTTPException(422, {"code": "invalid_url", "message": "source_url deve ser http(s)."})
        data = await file.read(s.max_upload_mb * 1024 * 1024 + 1)
        if len(data) > s.max_upload_mb * 1024 * 1024:
            raise HTTPException(413, {"code": "too_large", "message": f"Arquivo maior que {s.max_upload_mb} MB."})
        try:
            return c.ingestion.ingest(file.filename or "upload", data, title, source_url)
        except ExtractionError as e:
            raise HTTPException(422, {"code": "extraction_failed", "message": str(e), "supported": sorted(SUPPORTED)}) from e

    @app.delete("/v1/documents/{doc_id}", dependencies=auth, status_code=204)
    def delete_document(doc_id: int):
        if not c.ingestion.delete(doc_id):
            raise HTTPException(404, {"code": "not_found", "message": "Documento não encontrado."})

    @app.post("/v1/search", dependencies=auth)
    def search(req: SearchRequest):
        return [
            {
                "chunk_id": h.chunk.id,
                "document": h.chunk.document_title,
                "page": h.chunk.page,
                "score": h.score,
                "bm25_rank": h.bm25_rank,
                "dense_rank": h.dense_rank,
                "excerpt": h.chunk.text[:400],
            }
            for h in retriever.search(req.query, req.k)
        ]

    @app.post("/v1/plans", dependencies=auth)
    def plans(req: PlanRequest):
        if c.planner is None:
            raise LLMError(c.llm_error or "llm_not_configured", "Provedor de IA não configurado (LLM_API_KEY).", 503)
        return c.planner.generate(req.diagnosis.model_dump(), context=req.context, max_actions=req.max_actions, use_cache=req.use_cache)

    return app


def app_factory() -> FastAPI:  # usado pelo uvicorn: --factory app.main:app_factory
    configure()
    return create_app()
