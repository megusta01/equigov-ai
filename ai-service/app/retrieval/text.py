"""Normalização de texto em português para busca lexical (BM25) e para o verificador."""

from __future__ import annotations

import re
import unicodedata

STOPWORDS = frozenset(
    """
    a ao aos as ate com como da das de dela dele deles do dos e ela elas ele eles em entre era essa esse
    esta este eu foi for ha isso isto ja la lhe mais mas me mesmo muito na nao nas nem no nos o os ou
    para pela pelas pelo pelos por qual quando que se sem ser seu seus sua suas tambem te tem ter um uma
    umas uns sao sobre sob art inciso paragrafo lei sera serao deve devem pode podem
    """.split()
)

_TOKEN = re.compile(r"[a-z0-9]+")

# Redução leve de plural/flexão: suficiente para "pessoas"→"pessoa", "ações"→"acao",
# sem a agressividade de um stemmer completo (que confunde termos jurídicos).
_SUFFIXES = (("coes", "cao"), ("oes", "ao"), ("aes", "ao"), ("ais", "al"), ("eis", "el"), ("res", "r"), ("s", ""))


def fold(text: str) -> str:
    """minúsculas e sem acentos."""
    decomposed = unicodedata.normalize("NFKD", text.lower())
    return "".join(c for c in decomposed if not unicodedata.combining(c))


def _stem(token: str) -> str:
    if len(token) <= 3 or token.isdigit():
        return token
    for suffix, repl in _SUFFIXES:
        if token.endswith(suffix) and len(token) - len(suffix) >= 2:
            return token[: -len(suffix)] + repl
    return token


def tokenize(text: str) -> list[str]:
    return [_stem(t) for t in _TOKEN.findall(fold(text)) if t not in STOPWORDS and len(t) > 1]


def content_terms(text: str) -> set[str]:
    """Termos significativos (sem números curtos) para medir sobreposição de conteúdo."""
    return {t for t in tokenize(text) if not (t.isdigit() and len(t) < 4)}
