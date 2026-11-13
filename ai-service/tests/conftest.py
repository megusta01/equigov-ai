from __future__ import annotations

import zlib
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.generation.llm import FakeLLM
from app.main import create_app
from app.retrieval.retriever import Retriever
from app.retrieval.store import Store
from app.retrieval.text import tokenize

FIXTURES = Path(__file__).parent / "fixtures" / "corpus"
TOKEN = "test-token-0123456789"


class HashEmbedder:
    """Embedder determinístico (saco de palavras com hash) para testar o modo híbrido sem baixar modelo."""

    name = "hash-test"

    def __init__(self, dim: int = 128) -> None:
        self.dim = dim
        self.calls = 0

    def embed(self, texts: list[str]) -> np.ndarray:
        self.calls += 1
        m = np.zeros((len(texts), self.dim), dtype=np.float32)
        for i, t in enumerate(texts):
            for tok in tokenize(t):
                m[i, zlib.crc32(tok.encode()) % self.dim] += 1
        n = np.linalg.norm(m, axis=1, keepdims=True)
        return m / np.where(n == 0, 1, n)


def make_settings(tmp_path: Path, **overrides) -> Settings:
    base = dict(service_token=TOKEN, data_dir=tmp_path, llm_provider="fake", retrieval_mode="bm25", llm_api_key="")
    base.update(overrides)
    return Settings(**base)


@pytest.fixture
def settings(tmp_path) -> Settings:
    return make_settings(tmp_path)


@pytest.fixture
def store(tmp_path) -> Store:
    return Store(tmp_path / "t.sqlite3")


@pytest.fixture
def loaded_store(store) -> Store:
    from app.ingestion.service import IngestionService

    service = IngestionService(store, Retriever(store), None)
    for f in sorted(FIXTURES.glob("*.md")):
        service.ingest(f.name, f.read_bytes(), f.stem)
    return store


@pytest.fixture
def client(settings) -> TestClient:
    app = create_app(settings, llm=FakeLLM())
    c = TestClient(app)
    c.headers["X-Service-Token"] = TOKEN
    for f in sorted(FIXTURES.glob("*.md")):
        r = c.post("/v1/documents", files={"file": (f.name, f.read_bytes(), "text/markdown")}, data={"title": f.stem})
        assert r.status_code == 201, r.text
    return c


def diagnosis(**overrides) -> dict:
    """Diagnóstico no formato que o WordPress envia (subconjunto do /report)."""
    d = {
        "id": 42,
        "score": 81.0,
        "risk_level": "critical",
        "findings": [
            {
                "question_key": "libras",
                "question_text": "Como a unidade atende pessoas surdas usuárias de Libras?",
                "answer": "Não há recurso",
                "rule": 'Opção "Não há recurso" tem risco 1.00 (peso 3).',
                "message": "Sem atendimento em Libras, pessoas surdas dependem de terceiros para acessar o serviço.",
                "reference": "Lei nº 10.436/2002",
                "dimension": "comunicacao",
                "critical": True,
                "contribution": 15.0,
            },
            {
                "question_key": "canais",
                "question_text": "Por quais canais o principal serviço pode ser solicitado?",
                "answer": "Somente digital",
                "rule": 'Opção "Somente digital" tem risco 0.90 (peso 3).',
                "message": "Canal exclusivamente digital exclui pessoas sem acesso à internet ou dispositivo.",
                "dimension": "acesso",
                "critical": False,
                "contribution": 13.5,
            },
        ],
    }
    d.update(overrides)
    return d
