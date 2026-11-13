"""Persistência em SQLite: documentos, trechos (com embedding opcional) e cache de planos.

Por que SQLite e não um banco vetorial? O acervo previsto é de dezenas de documentos
(poucos milhares de trechos): busca exata em memória com numpy responde em milissegundos.
A interface Retriever isola essa escolha; migrar para pgvector/Qdrant não afeta o restante.
"""

from __future__ import annotations

import hashlib
import json
import sqlite3
import threading
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path

import numpy as np

SCHEMA = """
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS documents (
    id          INTEGER PRIMARY KEY,
    title       TEXT NOT NULL,
    filename    TEXT NOT NULL,
    source_url  TEXT,
    sha256      TEXT NOT NULL UNIQUE,
    pages       INTEGER NOT NULL,
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS chunks (
    id           INTEGER PRIMARY KEY,
    document_id  INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    page         INTEGER NOT NULL,
    ordinal      INTEGER NOT NULL,
    text         TEXT NOT NULL,
    embedding    BLOB,
    UNIQUE (document_id, ordinal)
);
CREATE INDEX IF NOT EXISTS idx_chunks_document ON chunks(document_id);

CREATE TABLE IF NOT EXISTS plan_cache (
    key         TEXT PRIMARY KEY,
    response    TEXT NOT NULL,
    created_at  TEXT NOT NULL
);
"""


@dataclass(frozen=True)
class StoredChunk:
    id: int
    document_id: int
    document_title: str
    source_url: str | None
    page: int
    text: str
    embedding: np.ndarray | None


def _now() -> str:
    return datetime.now(UTC).isoformat(timespec="seconds")


class Store:
    def __init__(self, path: Path | str) -> None:
        self.path = str(path)
        if self.path != ":memory:":
            Path(self.path).parent.mkdir(parents=True, exist_ok=True)
        self._conn = sqlite3.connect(self.path, check_same_thread=False)
        self._conn.row_factory = sqlite3.Row
        self._lock = threading.Lock()
        self._conn.executescript(SCHEMA)

    @contextmanager
    def _tx(self):
        with self._lock:
            try:
                yield self._conn
                self._conn.commit()
            except Exception:
                self._conn.rollback()
                raise

    @staticmethod
    def digest(data: bytes) -> str:
        return hashlib.sha256(data).hexdigest()

    def find_by_hash(self, sha256: str) -> dict | None:
        row = self._conn.execute("SELECT * FROM documents WHERE sha256 = ?", (sha256,)).fetchone()
        return dict(row) if row else None

    def add_document(
        self,
        *,
        title: str,
        filename: str,
        source_url: str | None,
        sha256: str,
        pages: int,
        chunks: list[tuple[int, int, str]],
        embeddings: np.ndarray | None,
    ) -> int:
        """Grava documento e trechos numa transação: ou tudo, ou nada."""
        with self._tx() as c:
            cur = c.execute(
                "INSERT INTO documents (title, filename, source_url, sha256, pages, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                (title, filename, source_url, sha256, pages, _now()),
            )
            doc_id = int(cur.lastrowid)
            c.executemany(
                "INSERT INTO chunks (document_id, page, ordinal, text, embedding) VALUES (?, ?, ?, ?, ?)",
                [
                    (
                        doc_id,
                        page,
                        ordinal,
                        text,
                        embeddings[i].astype(np.float32).tobytes() if embeddings is not None else None,
                    )
                    for i, (page, ordinal, text) in enumerate(chunks)
                ],
            )
            return doc_id

    def update_metadata(self, doc_id: int, title: str, source_url: str | None) -> None:
        with self._tx() as c:
            c.execute("UPDATE documents SET title = ?, source_url = ? WHERE id = ?", (title, source_url, doc_id))

    def list_documents(self) -> list[dict]:
        rows = self._conn.execute(
            """SELECT d.id, d.title, d.filename, d.source_url, d.pages, d.created_at, COUNT(c.id) AS chunks
               FROM documents d LEFT JOIN chunks c ON c.document_id = d.id
               GROUP BY d.id ORDER BY d.title"""
        ).fetchall()
        return [dict(r) for r in rows]

    def delete_document(self, doc_id: int) -> bool:
        with self._tx() as c:
            return c.execute("DELETE FROM documents WHERE id = ?", (doc_id,)).rowcount > 0

    def all_chunks(self) -> list[StoredChunk]:
        rows = self._conn.execute(
            """SELECT c.id, c.document_id, d.title, d.source_url, c.page, c.text, c.embedding
               FROM chunks c JOIN documents d ON d.id = c.document_id ORDER BY c.id"""
        ).fetchall()
        return [
            StoredChunk(
                id=r["id"],
                document_id=r["document_id"],
                document_title=r["title"],
                source_url=r["source_url"],
                page=r["page"],
                text=r["text"],
                embedding=np.frombuffer(r["embedding"], dtype=np.float32) if r["embedding"] else None,
            )
            for r in rows
        ]

    def missing_embeddings(self) -> list[tuple[int, str]]:
        return [(r["id"], r["text"]) for r in self._conn.execute("SELECT id, text FROM chunks WHERE embedding IS NULL")]

    def set_embeddings(self, items: list[tuple[int, np.ndarray]]) -> None:
        with self._tx() as c:
            c.executemany(
                "UPDATE chunks SET embedding = ? WHERE id = ?",
                [(v.astype(np.float32).tobytes(), cid) for cid, v in items],
            )

    def cache_get(self, key: str) -> dict | None:
        row = self._conn.execute("SELECT response FROM plan_cache WHERE key = ?", (key,)).fetchone()
        return json.loads(row["response"]) if row else None

    def cache_put(self, key: str, response: dict) -> None:
        with self._tx() as c:
            c.execute(
                "INSERT OR REPLACE INTO plan_cache (key, response, created_at) VALUES (?, ?, ?)",
                (key, json.dumps(response, ensure_ascii=False), _now()),
            )

    def cache_clear(self) -> None:
        with self._tx() as c:
            c.execute("DELETE FROM plan_cache")

    def signature(self) -> tuple[int, int, int]:
        """Assinatura barata do conteúdo indexado. Muda quando outro processo (ex.: a CLI) ingere ou remove."""
        r = self._conn.execute("SELECT COUNT(*), COALESCE(MAX(id), 0), SUM(embedding IS NULL) FROM chunks").fetchone()
        return int(r[0]), int(r[1]), int(r[2] or 0)

    def stats(self) -> dict:
        r = self._conn.execute(
            "SELECT (SELECT COUNT(*) FROM documents) AS documents, (SELECT COUNT(*) FROM chunks) AS chunks,"
            " (SELECT COUNT(*) FROM chunks WHERE embedding IS NULL) AS without_embedding"
        ).fetchone()
        return dict(r)
