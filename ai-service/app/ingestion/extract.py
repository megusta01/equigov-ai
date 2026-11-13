"""Extração de texto por página. Página é a unidade de citação: toda fonte aponta para (documento, página)."""

from __future__ import annotations

import io
import re
from dataclasses import dataclass
from html.parser import HTMLParser
from pathlib import Path

SUPPORTED = {".pdf", ".txt", ".md", ".html", ".htm"}


class ExtractionError(ValueError):
    pass


@dataclass(frozen=True)
class Page:
    number: int
    text: str


def extract(filename: str, data: bytes) -> list[Page]:
    ext = Path(filename).suffix.lower()
    if ext not in SUPPORTED:
        raise ExtractionError(f"Formato não suportado: {ext or 'sem extensão'}. Use {', '.join(sorted(SUPPORTED))}.")

    if ext == ".pdf":
        pages = _pdf(data)
    elif ext in {".html", ".htm"}:
        pages = _split_pages(_html(_decode(data)))
    else:
        pages = _split_pages(_decode(data))

    pages = [Page(p.number, _clean(p.text)) for p in pages]
    pages = [p for p in pages if p.text]
    if not pages:
        raise ExtractionError("Nenhum texto extraído. PDFs escaneados (imagem) precisam de OCR antes da ingestão.")
    return pages


def _pdf(data: bytes) -> list[Page]:
    from pypdf import PdfReader  # import tardio: só quem ingere PDF paga o custo
    from pypdf.errors import PdfReadError

    try:
        reader = PdfReader(io.BytesIO(data))
        return [Page(i + 1, page.extract_text() or "") for i, page in enumerate(reader.pages)]
    except (PdfReadError, ValueError) as e:
        raise ExtractionError(f"PDF inválido: {e}") from e


def _decode(data: bytes) -> str:
    # Páginas do Planalto (planalto.gov.br) ainda usam windows-1252.
    head = data[:2048].decode("ascii", errors="ignore").lower()
    m = re.search(r'charset=["\']?([a-z0-9_-]+)', head)
    candidates = ([m.group(1)] if m else []) + ["utf-8", "cp1252"]
    for enc in candidates:
        try:
            return data.decode(enc)
        except (UnicodeDecodeError, LookupError):
            continue
    return data.decode("utf-8", errors="replace")


def _split_pages(text: str) -> list[Page]:
    """Texto puro: quebra de página por form feed (\\f); sem \\f, tudo é página 1."""
    return [Page(i + 1, part) for i, part in enumerate(text.split("\f"))]


class _HTMLText(HTMLParser):
    BLOCK = {"p", "div", "br", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "table", "section", "article"}
    SKIP = {"script", "style", "head", "nav", "footer"}
    # Em leis do Planalto, texto revogado aparece riscado: não é norma vigente.
    REVOKED = {"strike", "s", "del"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self._skip = 0
        self._revoked = 0

    def handle_starttag(self, tag: str, attrs) -> None:
        if tag in self.SKIP:
            self._skip += 1
        elif tag in self.REVOKED:
            self._revoked += 1
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if tag in self.SKIP:
            self._skip = max(0, self._skip - 1)
        elif tag in self.REVOKED:
            self._revoked = max(0, self._revoked - 1)
        elif tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if not self._skip and not self._revoked:
            self.parts.append(data)


def _html(text: str) -> str:
    parser = _HTMLText()
    parser.feed(text)
    return "".join(parser.parts)


def _clean(text: str) -> str:
    text = text.replace("\r", "").replace(" ", " ")
    text = re.sub(r"-\n(?=[a-zà-ú])", "", text)  # hifenização de fim de linha em PDFs
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r" *\n *", "\n", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()
