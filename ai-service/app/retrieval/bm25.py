"""BM25 (Okapi). Implementado à mão: ~40 linhas, sem dependência, fácil de explicar."""

from __future__ import annotations

import math
from collections import Counter


class BM25:
    def __init__(self, docs: list[list[str]], k1: float = 1.5, b: float = 0.75) -> None:
        self.k1, self.b = k1, b
        self.tf = [Counter(d) for d in docs]
        self.len = [len(d) for d in docs]
        self.n = len(docs)
        self.avgdl = (sum(self.len) / self.n) if self.n else 0.0
        df: Counter[str] = Counter()
        for d in docs:
            df.update(set(d))
        # IDF com suavização (+1) para nunca ficar negativo em termos muito comuns.
        self.idf = {t: math.log(1 + (self.n - f + 0.5) / (f + 0.5)) for t, f in df.items()}

    def scores(self, query: list[str]) -> list[float]:
        out = [0.0] * self.n
        if not self.n:
            return out
        for term in set(query):
            idf = self.idf.get(term)
            if idf is None:
                continue
            for i, tf in enumerate(self.tf):
                f = tf.get(term)
                if f:
                    norm = self.k1 * (1 - self.b + self.b * self.len[i] / self.avgdl)
                    out[i] += idf * f * (self.k1 + 1) / (f + norm)
        return out
