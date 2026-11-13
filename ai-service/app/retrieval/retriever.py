"""Busca híbrida: BM25 (termos exatos, ótimo para texto legal) + embeddings (sinônimos e paráfrases),
combinados por Reciprocal Rank Fusion. RRF usa só a posição em cada ranking, então não é preciso
calibrar escalas de pontuação diferentes.
"""

from __future__ import annotations

import threading
from dataclasses import dataclass

import numpy as np

from .bm25 import BM25
from .embeddings import Embedder
from .store import Store, StoredChunk
from .text import tokenize

RRF_K = 60


@dataclass(frozen=True)
class Hit:
    chunk: StoredChunk
    score: float
    bm25_rank: int | None
    dense_rank: int | None


class Retriever:
    def __init__(self, store: Store, embedder: Embedder | None = None) -> None:
        self.store = store
        self.embedder = embedder
        self._lock = threading.Lock()
        self._chunks: list[StoredChunk] = []
        self._bm25 = BM25([])
        self._matrix: np.ndarray | None = None
        self._signature: tuple[int, int, int] | None = None
        self.rebuild()

    def refresh_if_stale(self) -> None:
        """O banco pode ter mudado por outro processo (CLI de ingestão): reconstrói o índice se preciso."""
        if self.store.signature() != self._signature:
            self.rebuild()

    @property
    def mode(self) -> str:
        return "hybrid" if self._matrix is not None else "bm25"

    def rebuild(self) -> None:
        """Recarrega o índice em memória. Chamado na subida e após ingestão/remoção."""
        if self.embedder is not None:
            missing = self.store.missing_embeddings()
            if missing:
                vectors = self.embedder.embed([t for _, t in missing])
                self.store.set_embeddings([(cid, vectors[i]) for i, (cid, _) in enumerate(missing)])

        chunks = self.store.all_chunks()
        bm25 = BM25([tokenize(c.text) for c in chunks])
        matrix = None
        if self.embedder is not None and chunks and all(c.embedding is not None for c in chunks):
            matrix = np.vstack([c.embedding for c in chunks])

        with self._lock:
            self._chunks, self._bm25, self._matrix = chunks, bm25, matrix
            self._signature = self.store.signature()

    def search(self, query: str, k: int = 5) -> list[Hit]:
        self.refresh_if_stale()
        with self._lock:
            chunks, bm25, matrix = self._chunks, self._bm25, self._matrix
        if not chunks:
            return []

        lexical = bm25.scores(tokenize(query))
        bm25_order = [i for i in np.argsort(lexical)[::-1] if lexical[i] > 0]
        bm25_rank = {int(i): r for r, i in enumerate(bm25_order)}

        dense_rank: dict[int, int] = {}
        if matrix is not None and self.embedder is not None:
            sims = matrix @ self.embedder.embed([query])[0]
            dense_rank = {int(i): r for r, i in enumerate(np.argsort(sims)[::-1][: max(k * 5, 20)])}

        fused: dict[int, float] = {}
        for ranks in (bm25_rank, dense_rank):
            for i, r in ranks.items():
                fused[i] = fused.get(i, 0.0) + 1.0 / (RRF_K + r + 1)

        best = sorted(fused.items(), key=lambda kv: kv[1], reverse=True)[:k]
        return [Hit(chunks[i], round(s, 6), bm25_rank.get(i), dense_rank.get(i)) for i, s in best]
