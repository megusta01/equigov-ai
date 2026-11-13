from __future__ import annotations

from app.evaluation.retrieval_eval import evaluate
from app.ingestion.service import IngestionService
from app.retrieval.bm25 import BM25
from app.retrieval.retriever import Retriever

from .conftest import FIXTURES, HashEmbedder


def test_bm25_ranks_relevant_document_first():
    docs = [["libra", "surdo", "interprete"], ["prioridade", "idoso"], ["libra", "dado"]]
    s = BM25(docs).scores(["interprete", "surdo"])
    assert s.index(max(s)) == 0
    assert s[1] == 0


def test_bm25_empty_index():
    assert BM25([]).scores(["x"]) == []


def test_lexical_search_finds_the_right_guide(loaded_store):
    hits = Retriever(loaded_store).search("intérprete de Libras para pessoas surdas", k=3)
    assert hits[0].chunk.document_title == "guia-atendimento"
    assert hits[0].bm25_rank == 0 and hits[0].dense_rank is None


def test_hybrid_mode_uses_both_rankings(store):
    embedder = HashEmbedder()
    retriever = Retriever(store, embedder)
    service = IngestionService(store, retriever, embedder)
    for f in sorted(FIXTURES.glob("*.md")):
        service.ingest(f.name, f.read_bytes(), f.stem)

    assert retriever.mode == "hybrid"
    hit = retriever.search("dados desagregados raça cor gênero", k=1)[0]
    assert hit.chunk.document_title == "nota-dados"
    assert hit.bm25_rank is not None and hit.dense_rank is not None


def test_embeddings_backfilled_when_switching_to_hybrid(loaded_store):
    # Documentos ingeridos em modo BM25 ganham embeddings quando o modelo passa a existir.
    assert loaded_store.stats()["without_embedding"] > 0
    retriever = Retriever(loaded_store, HashEmbedder())
    assert retriever.mode == "hybrid"
    assert loaded_store.stats()["without_embedding"] == 0


def test_ingestion_is_idempotent_and_delete_updates_index(store):
    retriever = Retriever(store)
    service = IngestionService(store, retriever, None)
    data = (FIXTURES / "nota-dados.md").read_bytes()

    first = service.ingest("nota.md", data, "Nota")
    assert first["status"] == "created"
    assert service.ingest("copia.md", data, "Nota")["status"] == "duplicate"
    assert service.ingest("copia.md", data, "Nota renomeada")["status"] == "updated"
    assert len(store.list_documents()) == 1
    assert store.list_documents()[0]["title"] == "Nota renomeada"

    assert retriever.search("raça cor território")
    assert service.delete(first["id"])
    assert retriever.search("raça cor território") == []
    assert not service.delete(first["id"])


def test_server_sees_documents_ingested_by_another_process(tmp_path):
    # Regressão: a CLI grava no SQLite e o servidor (outra conexão) precisa enxergar sem reiniciar.
    from app.retrieval.store import Store

    server_store, cli_store = Store(tmp_path / "shared.sqlite3"), Store(tmp_path / "shared.sqlite3")
    server = Retriever(server_store)
    assert server.search("Libras") == []

    IngestionService(cli_store, Retriever(cli_store), None).ingest("g.md", (FIXTURES / "guia-atendimento.md").read_bytes(), "guia")
    assert server.search("Libras")[0].chunk.document_title == "guia"


def test_evaluation_metrics(loaded_store):
    cases = [
        {"query": "atendimento prioritário a idosos e gestantes", "expected_documents": ["guia-atendimento"]},
        {"query": "avaliação com leitores de tela", "expected_documents": ["manual-digital"]},
        {"query": "consulta que não existe no acervo xyzw", "expected_documents": ["nota-dados"]},
    ]
    report = evaluate(Retriever(loaded_store), cases, k=3)
    assert report["hit@1"] == round(2 / 3, 3)
    assert report["rows"][2]["rank"] is None
