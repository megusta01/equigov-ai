"""Linha de comando do serviço de IA.

python -m app.cli ingest /corpus              ingere arquivos (usa corpus/sources.json para títulos e URLs)
python -m app.cli list                        documentos indexados
python -m app.cli search "intérprete de libras"
python -m app.cli eval                        avaliação da recuperação
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from .config import Settings
from .evaluation.retrieval_eval import evaluate, load_cases
from .ingestion.extract import SUPPORTED, ExtractionError
from .ingestion.service import IngestionService
from .retrieval.embeddings import load_embedder
from .retrieval.retriever import Retriever
from .retrieval.store import Store

DEFAULT_CASES = Path(__file__).parent / "evaluation" / "cases.json"


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="equigov-ai")
    sub = p.add_subparsers(dest="cmd", required=True)
    ing = sub.add_parser("ingest")
    ing.add_argument("paths", nargs="+", type=Path)
    sub.add_parser("list")
    de = sub.add_parser("delete")
    de.add_argument("ids", nargs="+", type=int)
    se = sub.add_parser("search")
    se.add_argument("query")
    se.add_argument("-k", type=int, default=5)
    ev = sub.add_parser("eval")
    ev.add_argument("--dataset", type=Path, default=DEFAULT_CASES)
    ev.add_argument("-k", type=int, default=5)
    ev.add_argument("--min-hit", type=float, default=0.0, help="falha (exit 1) se hit@k ficar abaixo disso")
    args = p.parse_args(argv)

    s = Settings()
    store = Store(s.db_path)
    embedder = load_embedder(s.retrieval_mode, s.embedding_model)
    retriever = Retriever(store, embedder)

    if args.cmd == "ingest":
        service = IngestionService(store, retriever, embedder)
        for f in _files(args.paths):
            meta = _metadata(f)
            try:
                r = service.ingest(f.name, f.read_bytes(), meta.get("title"), meta.get("source_url"))
                print(
                    f"{r['status']:9} #{r['id']:<3} {r['title']}"
                    + (f"  ({r['pages']} pág., {r['chunks']} trechos)" if r["status"] == "created" else "")
                )
            except ExtractionError as e:
                print(f"{'erro':9}      {f.name}: {e}", file=sys.stderr)
        return 0

    if args.cmd == "list":
        for d in store.list_documents():
            print(f"#{d['id']:<3} {d['title']}  [{d['pages']} pág., {d['chunks']} trechos]")
        return 0

    if args.cmd == "delete":
        service = IngestionService(store, retriever, embedder)
        for doc_id in args.ids:
            print(f"#{doc_id} {'removido' if service.delete(doc_id) else 'não encontrado'}")
        return 0

    if args.cmd == "search":
        print(f"modo: {retriever.mode}")
        for h in retriever.search(args.query, args.k):
            print(f"\n[{h.score:.4f}] {h.chunk.document_title} — p. {h.chunk.page}  (bm25 #{h.bm25_rank}, denso #{h.dense_rank})")
            print("   " + h.chunk.text[:300].replace("\n", " "))
        return 0

    report = evaluate(retriever, load_cases(args.dataset), args.k)
    for r in report.pop("rows"):
        mark = "✔" if r["rank"] else "✘"
        print(f" {mark} rank={r['rank'] or '-':<2} {r['query'][:60]:60}  esperado: {r['expected']}")
    print(json.dumps(report, ensure_ascii=False))
    return 1 if report[f"hit@{args.k}"] < args.min_hit else 0


def _files(paths: list[Path]) -> list[Path]:
    """Arquivos suportados; ignora a documentação da própria pasta (README*)."""
    out: list[Path] = []
    for p in paths:
        candidates = sorted(p.rglob("*")) if p.is_dir() else [p]
        out.extend(f for f in candidates if f.suffix.lower() in SUPPORTED and not f.name.lower().startswith("readme"))
    return out


def _metadata(f: Path) -> dict:
    """Título e URL do sources.json. Casa pelo nome sem extensão e sem diferenciar maiúsculas:
    'l13146.htm' no manifesto vale para 'l13146.html' ou 'L13146.HTM' no disco."""
    manifest = f.parent / "sources.json"
    if not manifest.exists():
        return {}
    entries = json.loads(manifest.read_text(encoding="utf-8"))
    by_stem = {Path(name).stem.lower(): meta for name, meta in entries.items()}
    return entries.get(f.name) or by_stem.get(f.stem.lower(), {})


if __name__ == "__main__":
    sys.exit(main())
