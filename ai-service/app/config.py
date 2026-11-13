"""Configuração via variáveis de ambiente (12-factor). Nada de segredo em código."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path


def _int(name: str, default: int) -> int:
    return int(os.environ.get(name, default))


def _float(name: str, default: float) -> float:
    return float(os.environ.get(name, default))


@dataclass(frozen=True)
class Settings:
    service_token: str = field(default_factory=lambda: os.environ.get("AI_SERVICE_TOKEN", ""))
    data_dir: Path = field(default_factory=lambda: Path(os.environ.get("DATA_DIR", "/data")))

    # LLM: qualquer API compatível com o formato OpenAI (Groq, Gemini, OpenRouter, Ollama...)
    llm_provider: str = field(default_factory=lambda: os.environ.get("LLM_PROVIDER", "openai_compatible"))
    llm_base_url: str = field(default_factory=lambda: os.environ.get("LLM_BASE_URL", "https://api.groq.com/openai/v1"))
    llm_api_key: str = field(default_factory=lambda: os.environ.get("LLM_API_KEY", ""))
    llm_model: str = field(default_factory=lambda: os.environ.get("LLM_MODEL", "openai/gpt-oss-120b"))
    llm_timeout_s: float = field(default_factory=lambda: _float("LLM_TIMEOUT_S", 45))
    llm_max_retries: int = field(default_factory=lambda: _int("LLM_MAX_RETRIES", 2))
    llm_max_tokens: int = field(default_factory=lambda: _int("LLM_MAX_TOKENS", 2000))
    llm_temperature: float = field(default_factory=lambda: _float("LLM_TEMPERATURE", 0.2))

    # Recuperação
    retrieval_mode: str = field(default_factory=lambda: os.environ.get("RETRIEVAL_MODE", "hybrid"))
    embedding_model: str = field(
        default_factory=lambda: os.environ.get("EMBEDDING_MODEL", "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2")
    )
    top_k_per_finding: int = field(default_factory=lambda: _int("TOP_K_PER_FINDING", 4))
    max_sources: int = field(default_factory=lambda: _int("MAX_SOURCES", 10))
    max_findings: int = field(default_factory=lambda: _int("MAX_FINDINGS", 6))

    # Verificação
    min_support: float = field(default_factory=lambda: _float("MIN_SUPPORT", 0.12))

    max_upload_mb: int = field(default_factory=lambda: _int("MAX_UPLOAD_MB", 20))

    @property
    def db_path(self) -> Path:
        return self.data_dir / "equigov-ai.sqlite3"

    def validate(self) -> None:
        if len(self.service_token) < 16:
            raise RuntimeError("AI_SERVICE_TOKEN ausente ou curto demais (mínimo 16 caracteres).")
        if self.retrieval_mode not in {"bm25", "hybrid"}:
            raise RuntimeError("RETRIEVAL_MODE deve ser 'bm25' ou 'hybrid'.")
        if self.llm_provider not in {"openai_compatible", "fake"}:
            raise RuntimeError("LLM_PROVIDER deve ser 'openai_compatible' ou 'fake'.")
