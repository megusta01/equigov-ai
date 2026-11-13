from __future__ import annotations

import logging
from pathlib import Path

from ..retrieval.embeddings import Embedder
from ..retrieval.retriever import Retriever
from ..retrieval.store import Store
from .chunker import chunk_pages
from .extract import extract

log = logging.getLogger(__name__)


class IngestionService:
    def __init__(self, store: Store, retriever: Retriever, embedder: Embedder | None) -> None:
        self.store, self.retriever, self.embedder = store, retriever, embedder

    def ingest(self, filename: str, data: bytes, title: str | None = None, source_url: str | None = None) -> dict:
        sha = Store.digest(data)
        existing = self.store.find_by_hash(sha)
        if existing:  # idempotente: o mesmo arquivo nunca é indexado duas vezes
            new_title = title.strip()[:300] if title else existing["title"]
            new_url = source_url or existing["source_url"]
            if (new_title, new_url) != (existing["title"], existing["source_url"]):
                # Mesmo conteúdo, metadados corrigidos: atualiza sem reindexar.
                self.store.update_metadata(existing["id"], new_title, new_url)
                self.store.cache_clear()  # planos em cache carregam o título antigo
                self.retriever.rebuild()
                return {"id": existing["id"], "status": "updated", "title": new_title}
            return {"id": existing["id"], "status": "duplicate", "title": existing["title"]}

        pages = extract(filename, data)
        chunks = chunk_pages(pages)
        embeddings = self.embedder.embed([c.text for c in chunks]) if self.embedder else None

        doc_id = self.store.add_document(
            title=(title or Path(filename).stem).strip()[:300],
            filename=Path(filename).name,
            source_url=source_url,
            sha256=sha,
            pages=len(pages),
            chunks=[(c.page, c.ordinal, c.text) for c in chunks],
            embeddings=embeddings,
        )
        self.retriever.rebuild()
        log.info("document_ingested", extra={"document_id": doc_id, "pages": len(pages), "chunks": len(chunks)})
        return {"id": doc_id, "status": "created", "title": title or Path(filename).stem, "pages": len(pages), "chunks": len(chunks)}

    def delete(self, doc_id: int) -> bool:
        deleted = self.store.delete_document(doc_id)
        if deleted:
            self.store.cache_clear()  # planos em cache podem citar o documento removido
            self.retriever.rebuild()
        return deleted
