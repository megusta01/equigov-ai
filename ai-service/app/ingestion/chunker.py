"""Divisão em trechos. Regras:
- um trecho nunca cruza páginas (a citação "página N" precisa ser verdadeira);
- preferimos cortar antes de "Art." e entre parágrafos, para não partir um dispositivo legal ao meio;
- sobreposição pequena entre trechos vizinhos preserva contexto na fronteira.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

from .extract import Page

_UNIT_BREAK = re.compile(r"\n(?=\s*Art\.\s*\d)|\n\s*\n")
_SENTENCE = re.compile(r"(?<=[.;:])\s+")


@dataclass(frozen=True)
class Chunk:
    page: int
    ordinal: int
    text: str


def chunk_pages(pages: list[Page], target: int = 900, overlap: int = 150) -> list[Chunk]:
    chunks: list[Chunk] = []
    for page in pages:
        for text in _pack(_units(page.text, target), target, overlap):
            chunks.append(Chunk(page.number, len(chunks), text))
    return chunks


def _units(text: str, target: int) -> list[str]:
    units: list[str] = []
    for block in _UNIT_BREAK.split(text):
        block = re.sub(r"\s*\n\s*", " ", block).strip()
        if not block:
            continue
        if len(block) <= target:
            units.append(block)
        else:  # bloco gigante: quebra por frase
            units.extend(s for s in _SENTENCE.split(block) if s)
    return units


def _pack(units: list[str], target: int, overlap: int) -> list[str]:
    out: list[str] = []
    current = ""
    for unit in units:
        while len(unit) > target * 1.5:  # frase absurdamente longa: corte duro
            head, unit = unit[:target], unit[target - overlap :]
            if current:
                out.append(current)
                current = ""
            out.append(head)
        if current and len(current) + 1 + len(unit) > target:
            out.append(current)
            tail = current[-overlap:]
            tail = tail[tail.find(" ") + 1 :] if " " in tail else tail
            current = f"{tail} {unit}" if overlap else unit
        else:
            current = f"{current} {unit}".strip()
    if current:
        out.append(current)
    return out
