from __future__ import annotations

import io

import pytest

from app.ingestion.chunker import chunk_pages
from app.ingestion.extract import ExtractionError, Page, extract
from app.retrieval.text import fold, tokenize


def test_fold_and_tokenize_portuguese():
    assert fold("Ações de INCLUSÃO") == "acoes de inclusao"
    assert tokenize("As ações para pessoas com deficiência") == ["acao", "pessoa", "deficiencia"]


def test_html_planalto_style_cp1252_and_revoked_text_is_ignored():
    html = (
        '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1252">'
        "<style>p{}</style></head><body><p>Art. 1º Fica garantido o atendimento.</p>"
        "<p><strike>Art. 2º Texto revogado.</strike></p><p>Art. 3º Intérprete de Libras.</p></body></html>"
    ).encode("cp1252")
    text = extract("lei.htm", html)[0].text
    assert "Intérprete de Libras" in text
    assert "revogado" not in text
    assert "p{}" not in text


def test_pdf_keeps_page_numbers():
    pytest.importorskip("reportlab")
    from reportlab.pdfgen import canvas

    buf = io.BytesIO()
    pdf = canvas.Canvas(buf)
    pdf.drawString(72, 720, "Primeira pagina sobre acessibilidade")
    pdf.showPage()
    pdf.drawString(72, 720, "Segunda pagina sobre Libras")
    pdf.save()

    pages = extract("doc.pdf", buf.getvalue())
    assert [p.number for p in pages] == [1, 2]
    assert "Libras" in pages[1].text


def test_rejects_unsupported_and_empty_documents():
    with pytest.raises(ExtractionError, match="não suportado"):
        extract("planilha.xlsx", b"x")
    with pytest.raises(ExtractionError, match="Nenhum texto"):
        extract("vazio.txt", b"   \n  ")
    with pytest.raises(ExtractionError, match="PDF inválido"):
        extract("quebrado.pdf", b"isto nao e um pdf")


def test_chunks_never_cross_pages():
    pages = [Page(1, "Texto da página um. " * 80), Page(2, "Texto da página dois. " * 80)]
    chunks = chunk_pages(pages, target=300, overlap=50)
    assert {c.page for c in chunks} == {1, 2}
    assert all(("um" in c.text) != ("dois" in c.text) for c in chunks)
    assert [c.ordinal for c in chunks] == list(range(len(chunks)))
    assert all(len(c.text) <= 300 * 1.5 for c in chunks)


def test_chunker_prefers_breaking_before_articles():
    text = "\n".join(f"Art. {i}º " + "disposição sobre atendimento inclusivo " * 6 for i in range(1, 6))
    chunks = chunk_pages([Page(1, text)], target=500, overlap=0)
    assert len(chunks) > 1
    assert all(c.text.startswith("Art.") for c in chunks)


def test_overlap_carries_context_between_chunks():
    text = "\n\n".join(f"Parágrafo {i} com conteúdo relevante sobre linguagem simples." for i in range(20))
    chunks = chunk_pages([Page(1, text)], target=200, overlap=60)
    assert len(chunks) > 2
    tail = chunks[0].text[-30:].split(" ", 1)[-1]
    assert tail in chunks[1].text
