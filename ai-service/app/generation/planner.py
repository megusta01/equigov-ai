"""Geração de plano de ação com RAG e rastreabilidade.

Cadeia que a resposta preserva para cada ação:
  achado do diagnóstico (pergunta, resposta, regra)  →  trecho recuperado (documento, página)
  →  ação sugerida (com status de verificação)  →  aprovação humana (no WordPress)
"""

from __future__ import annotations

import hashlib
import json
import logging
import time

from ..config import Settings
from ..retrieval.retriever import Hit, Retriever
from ..retrieval.store import Store
from .llm import LLMClient, LLMError
from .prompts import PROMPT_VERSION, SYSTEM, build_user_message, repair_message
from .verifier import ParseError, parse_plan, verify

log = logging.getLogger(__name__)

EXCERPT_CHARS = 1200


class Planner:
    def __init__(self, settings: Settings, retriever: Retriever, llm: LLMClient, store: Store) -> None:
        self.s, self.retriever, self.llm, self.store = settings, retriever, llm, store

    def generate(self, diagnosis: dict, *, context: str | None = None, max_actions: int = 5, use_cache: bool = True) -> dict:
        started = time.monotonic()
        findings = sorted(diagnosis["findings"], key=lambda f: (f.get("critical", False), f.get("contribution", 0)), reverse=True)
        findings = findings[: self.s.max_findings]
        if not findings:
            return self._empty("O diagnóstico não tem achados de risco: nenhuma ação necessária.")

        sources, per_finding = self._retrieve(findings)
        if not sources:
            raise LLMError("empty_library", "A biblioteca não tem documentos relevantes. Ingerir documentos antes de gerar planos.", 409)

        llm_findings = [
            {
                "key": f["question_key"],
                "question_text": f["question_text"],
                "answer": f.get("answer", ""),
                "message": f.get("message", ""),
                "reference": f.get("reference"),
                "critical": f.get("critical", False),
                "contribution": f.get("contribution", 0),
                "retrieved_sources": per_finding[f["question_key"]],
            }
            for f in findings
        ]
        llm_sources = [
            {"id": sid, "document": h.chunk.document_title, "page": h.chunk.page, "text": h.chunk.text[:EXCERPT_CHARS]}
            for sid, h in sources.items()
        ]

        cache_key = self._cache_key(llm_findings, llm_sources, context, max_actions)
        if use_cache and (cached := self.store.cache_get(cache_key)):
            cached["meta"]["cache_hit"] = True
            return cached

        messages = [
            {"role": "system", "content": SYSTEM},
            {
                "role": "user",
                "content": build_user_message(findings=llm_findings, sources=llm_sources, context=context, max_actions=max_actions),
            },
        ]
        result = self.llm.complete(messages, max_tokens=self.s.llm_max_tokens)
        usage, attempts = dict(result.usage), result.attempts
        try:
            plan = parse_plan(result.text)
        except ParseError as e:
            # Uma única tentativa de reparo, com o erro explicado ao modelo.
            log.warning("llm_parse_retry", extra={"error": str(e)})
            messages += [{"role": "assistant", "content": result.text[:4000]}, {"role": "user", "content": repair_message(str(e))}]
            result = self.llm.complete(messages, max_tokens=self.s.llm_max_tokens)
            attempts += result.attempts
            for k, v in result.usage.items():
                usage[k] = usage.get(k, 0) + v
            try:
                plan = parse_plan(result.text)
            except ParseError as e2:
                raise LLMError("llm_invalid_output", f"O modelo não produziu um plano válido: {e2}", 502) from e2

        plan.actions = plan.actions[:max_actions]
        report = verify(
            plan,
            finding_keys={f["question_key"] for f in findings},
            sources={sid: h.chunk.text for sid, h in sources.items()},
            min_support=self.s.min_support,
        )

        findings_by_key = {f["question_key"]: f for f in findings}
        actions, rejected = [], []
        for va in report.actions:
            item = {
                **va.action.model_dump(include={"title", "description", "priority", "deadline_days", "indicator", "rationale"}),
                "verification": {"status": va.status, "support": va.support, "reasons": va.reasons},
                "findings": [
                    {k: findings_by_key[key].get(k) for k in ("question_key", "question_text", "answer", "rule", "reference", "critical")}
                    for key in va.valid_findings
                ],
                "sources": [self._source_view(sid, sources[sid]) for sid in va.valid_sources],
            }
            (rejected if va.status == "rejected" else actions).append(item)

        response = {
            "actions": actions,
            "rejected": rejected,
            "retrieval": {
                "mode": self.retriever.mode,
                "per_finding": per_finding,
                "sources": {sid: self._source_view(sid, h) for sid, h in sources.items()},
            },
            "meta": {
                "provider": self.llm.provider,
                "model": result.model,
                "prompt_version": PROMPT_VERSION,
                "latency_ms": int((time.monotonic() - started) * 1000),
                "llm_attempts": attempts,
                "usage": usage,
                "verification": report.summary(),
                "cache_hit": False,
                "requires_human_approval": True,
            },
        }
        self.store.cache_put(cache_key, response)
        log.info(
            "plan_generated",
            extra={"diagnosis_id": diagnosis.get("id"), **response["meta"]["verification"], "latency_ms": response["meta"]["latency_ms"]},
        )
        return response

    def _retrieve(self, findings: list[dict]) -> tuple[dict[str, Hit], dict[str, list[str]]]:
        """Busca por achado, deduplica trechos e numera S1..Sn na ordem de relevância."""
        by_chunk: dict[int, tuple[str, Hit]] = {}
        per_finding: dict[str, list[str]] = {}
        for f in findings:
            query = " ".join(filter(None, [f["question_text"], f.get("answer"), f.get("message"), f.get("reference")]))
            ids = []
            for hit in self.retriever.search(query, k=self.s.top_k_per_finding):
                if hit.chunk.id not in by_chunk:
                    if len(by_chunk) >= self.s.max_sources:
                        continue
                    by_chunk[hit.chunk.id] = (f"S{len(by_chunk) + 1}", hit)
                ids.append(by_chunk[hit.chunk.id][0])
            per_finding[f["question_key"]] = ids
        return {sid: hit for sid, hit in by_chunk.values()}, per_finding

    @staticmethod
    def _source_view(sid: str, hit: Hit) -> dict:
        return {
            "id": sid,
            "document_id": hit.chunk.document_id,
            "document": hit.chunk.document_title,
            "source_url": hit.chunk.source_url,
            "page": hit.chunk.page,
            "chunk_id": hit.chunk.id,
            "excerpt": hit.chunk.text[:600],
            "score": hit.score,
        }

    def _cache_key(self, findings, sources, context, max_actions) -> str:
        raw = json.dumps(
            [
                PROMPT_VERSION,
                self.llm.provider,
                self.llm.model,
                findings,
                [s["id"] + str(s["page"]) + s["text"] for s in sources],
                context,
                max_actions,
            ],
            ensure_ascii=False,
            sort_keys=True,
        )
        return hashlib.sha256(raw.encode()).hexdigest()

    def _empty(self, note: str) -> dict:
        return {
            "actions": [],
            "rejected": [],
            "retrieval": {"mode": self.retriever.mode, "per_finding": {}, "sources": {}},
            "meta": {
                "provider": self.llm.provider,
                "model": self.llm.model,
                "prompt_version": PROMPT_VERSION,
                "note": note,
                "cache_hit": False,
                "requires_human_approval": True,
            },
        }
