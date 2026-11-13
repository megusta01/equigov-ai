"""Avaliação da recuperação: dado um conjunto de perguntas com o documento esperado,
mede hit@k (o documento certo aparece entre os k primeiros?) e MRR (quão alto ele aparece).

Rodar: python -m app.cli eval --dataset app/evaluation/cases.json
"""

from __future__ import annotations

import json
from pathlib import Path

from ..retrieval.retriever import Retriever
from ..retrieval.text import fold


def load_cases(path: Path | str) -> list[dict]:
    return json.loads(Path(path).read_text(encoding="utf-8"))["cases"]


def evaluate(retriever: Retriever, cases: list[dict], k: int = 5) -> dict:
    rows, hits1, hitsk, rr = [], 0, 0, 0.0
    for case in cases:
        expected = [fold(e) for e in case["expected_documents"]]
        results = retriever.search(case["query"], k)
        rank = next((i + 1 for i, h in enumerate(results) if any(e in fold(h.chunk.document_title) for e in expected)), None)
        hits1 += rank == 1
        hitsk += rank is not None
        rr += 1 / rank if rank else 0
        rows.append(
            {
                "query": case["query"],
                "expected": " | ".join(case["expected_documents"]),
                "rank": rank,
                "top": results[0].chunk.document_title if results else None,
            }
        )
    n = len(cases) or 1
    return {
        "mode": retriever.mode,
        "k": k,
        "cases": len(cases),
        "hit@1": round(hits1 / n, 3),
        f"hit@{k}": round(hitsk / n, 3),
        "mrr": round(rr / n, 3),
        "rows": rows,
    }
