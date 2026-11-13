from __future__ import annotations

import json

import pytest

from app.cli import main

from .conftest import FIXTURES


@pytest.fixture
def corpus(tmp_path, monkeypatch):
    monkeypatch.setenv("DATA_DIR", str(tmp_path / "data"))
    monkeypatch.setenv("RETRIEVAL_MODE", "bm25")
    d = tmp_path / "corpus"
    d.mkdir()
    # Nome no disco difere do manifesto (.html x .htm, maiúsculas): caso real de "Salvar como".
    (d / "Guia.HTML").write_bytes(b"<p>Atendimento em Libras para pessoas surdas.</p>")
    (d / "README.md").write_text("# Acervo\nInstruções, não é documento.", encoding="utf-8")
    (d / "sources.json").write_text(
        json.dumps({"guia.htm": {"title": "Guia 99.999 de teste", "source_url": "https://exemplo.gov.br/guia"}}),
        encoding="utf-8",
    )
    return d


def test_ingest_matches_manifest_ignoring_extension_and_skips_readme(corpus, capsys):
    assert main(["ingest", str(corpus)]) == 0
    out = capsys.readouterr().out
    assert "Guia 99.999 de teste" in out
    assert "README" not in out

    main(["list"])
    listed = capsys.readouterr().out
    assert listed.count("#") == 1


def test_reingest_fixes_metadata_without_reindexing(corpus, capsys):
    (corpus / "sources.json").rename(corpus / "sources.bak")
    main(["ingest", str(corpus)])
    assert "created" in capsys.readouterr().out  # sem manifesto: título = nome do arquivo

    (corpus / "sources.bak").rename(corpus / "sources.json")
    main(["ingest", str(corpus)])
    out = capsys.readouterr().out
    assert "updated" in out and "Guia 99.999 de teste" in out

    main(["ingest", str(corpus)])
    assert "duplicate" in capsys.readouterr().out


def test_delete_command(corpus, capsys):
    main(["ingest", str(corpus)])
    capsys.readouterr()
    main(["delete", "1", "999"])
    out = capsys.readouterr().out
    assert "#1 removido" in out and "#999 não encontrado" in out


def test_eval_exit_code_respects_min_hit(corpus, tmp_path):
    main(["ingest", str(corpus)])
    cases = tmp_path / "cases.json"
    cases.write_text(json.dumps({"cases": [{"query": "intérprete de Libras", "expected_documents": ["99.999"]}]}))
    assert main(["eval", "--dataset", str(cases), "--min-hit", "1"]) == 0
    cases.write_text(json.dumps({"cases": [{"query": "intérprete de Libras", "expected_documents": ["inexistente"]}]}))
    assert main(["eval", "--dataset", str(cases), "--min-hit", "1"]) == 1


def test_fixture_corpus_is_ingestible():
    assert len(list(FIXTURES.glob("*.md"))) == 3
