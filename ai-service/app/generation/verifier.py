"""Verificação da saída do LLM. Nada sai deste serviço sem passar por aqui.

Para cada ação proposta:
- finding_keys precisam existir no diagnóstico;
- source_ids precisam existir entre os trechos enviados (citação inventada é removida e contabilizada);
- o texto da ação precisa ter sobreposição mínima de conteúdo com algum trecho citado (suporte).

Resultado: grounded (fundamentada), weak (cita fonte real mas com pouco suporte textual)
ou rejected (sem achado válido ou sem fonte válida: não é devolvida como sugestão).
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from typing import Literal

from pydantic import BaseModel, Field, ValidationError, field_validator

from ..retrieval.text import content_terms


class ParseError(ValueError):
    pass


class RawAction(BaseModel):
    title: str = Field(min_length=3, max_length=200)
    description: str = Field(min_length=10, max_length=2000)
    finding_keys: list[str] = Field(default_factory=list, max_length=10)
    source_ids: list[str] = Field(default_factory=list, max_length=10)
    priority: Literal["alta", "media", "baixa"] = "media"
    deadline_days: int | None = Field(default=None, ge=1, le=730)
    indicator: str = Field(default="", max_length=500)
    rationale: str = Field(default="", max_length=2000)

    @field_validator("priority", mode="before")
    @classmethod
    def _fold_priority(cls, v):
        return {"média": "media", "medium": "media", "high": "alta", "low": "baixa"}.get(str(v).lower(), str(v).lower())


class RawPlan(BaseModel):
    actions: list[RawAction] = Field(default_factory=list, max_length=20)


@dataclass
class VerifiedAction:
    action: RawAction
    status: Literal["grounded", "weak", "rejected"]
    valid_findings: list[str]
    valid_sources: list[str]
    invalid_sources: list[str]
    support: dict[str, float]
    reasons: list[str] = field(default_factory=list)


@dataclass
class VerificationReport:
    actions: list[VerifiedAction]

    def summary(self) -> dict:
        total_cites = sum(len(a.valid_sources) + len(a.invalid_sources) for a in self.actions)
        invalid = sum(len(a.invalid_sources) for a in self.actions)
        by_status = {s: sum(1 for a in self.actions if a.status == s) for s in ("grounded", "weak", "rejected")}
        return {
            "proposed": len(self.actions),
            **by_status,
            "citations": total_cites,
            "invalid_citations": invalid,
            "citation_validity": round(1 - invalid / total_cites, 3) if total_cites else None,
        }


def parse_plan(text: str) -> RawPlan:
    cleaned = re.sub(r"^```(?:json)?\s*|\s*```$", "", text.strip(), flags=re.S)
    try:
        return RawPlan.model_validate(json.loads(cleaned))
    except json.JSONDecodeError as e:
        raise ParseError(f"JSON inválido: {e.msg}") from e
    except ValidationError as e:
        raise ParseError(f"JSON fora do formato: {e.errors()[0]['loc']} {e.errors()[0]['msg']}") from e


def support_score(action: RawAction, source_text: str) -> float:
    """Fração dos termos de conteúdo da ação que aparecem no trecho citado."""
    action_terms = content_terms(f"{action.title} {action.description} {action.rationale}")
    if not action_terms:
        return 0.0
    return round(len(action_terms & content_terms(source_text)) / len(action_terms), 3)


def verify(plan: RawPlan, *, finding_keys: set[str], sources: dict[str, str], min_support: float) -> VerificationReport:
    out: list[VerifiedAction] = []
    for a in plan.actions:
        vf = [k for k in dict.fromkeys(a.finding_keys) if k in finding_keys]
        vs = [s for s in dict.fromkeys(a.source_ids) if s in sources]
        inv = [s for s in dict.fromkeys(a.source_ids) if s not in sources]
        support = {s: support_score(a, sources[s]) for s in vs}

        reasons = []
        if inv:
            reasons.append(f"citações inexistentes removidas: {', '.join(inv)}")
        if not vf:
            reasons.append("não referencia nenhum achado do diagnóstico")
        if not vs:
            reasons.append("sem fonte válida")

        if not vf or not vs:
            status = "rejected"
        elif max(support.values()) < min_support:
            status = "weak"
            reasons.append(f"suporte textual baixo (máx. {max(support.values()):.2f} < {min_support:.2f})")
        else:
            status = "grounded"

        out.append(VerifiedAction(a, status, vf, vs, inv, support, reasons))
    return VerificationReport(out)
