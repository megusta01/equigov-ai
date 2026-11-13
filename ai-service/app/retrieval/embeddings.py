"""Embeddings locais (sem API, sem custo). O modelo é baixado no build da imagem Docker."""

from __future__ import annotations

import logging
import os
from typing import Protocol

import numpy as np

log = logging.getLogger(__name__)


class Embedder(Protocol):
    name: str

    def embed(self, texts: list[str]) -> np.ndarray:
        """Retorna matriz (n, dim) com vetores normalizados (norma 1)."""
        ...


def _normalize(m: np.ndarray) -> np.ndarray:
    norms = np.linalg.norm(m, axis=1, keepdims=True)
    return m / np.where(norms == 0, 1, norms)


class FastEmbedEmbedder:
    def __init__(self, model: str) -> None:
        from fastembed import TextEmbedding  # dependência opcional

        self.name = model
        # Na imagem Docker o modelo é baixado no build para FASTEMBED_CACHE_PATH e o runtime roda offline.
        self._model = TextEmbedding(model_name=model, cache_dir=os.environ.get("FASTEMBED_CACHE_PATH"))

    def embed(self, texts: list[str]) -> np.ndarray:
        if not texts:
            return np.zeros((0, 0), dtype=np.float32)
        return _normalize(np.array(list(self._model.embed(texts, batch_size=32)), dtype=np.float32))


def load_embedder(mode: str, model: str) -> Embedder | None:
    """Em modo híbrido tenta carregar o modelo; se falhar, o serviço segue em BM25 e avisa no /health."""
    if mode != "hybrid":
        return None
    try:
        return FastEmbedEmbedder(model)
    except Exception as e:  # noqa: BLE001 - qualquer falha aqui deve degradar, não derrubar
        log.warning("embeddings_unavailable", extra={"model": model, "error": str(e)})
        return None
