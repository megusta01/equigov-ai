"""Contratos de entrada. Limites de tamanho em tudo: entrada é não confiável, mesmo vinda do WordPress."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class Finding(BaseModel):
    question_key: str = Field(min_length=1, max_length=100, pattern=r"^[a-z][a-z0-9_]*$")
    question_text: str = Field(min_length=1, max_length=500)
    answer: str = Field(default="", max_length=300)
    rule: str = Field(default="", max_length=300)
    message: str = Field(default="", max_length=1500)
    reference: str | None = Field(default=None, max_length=300)
    dimension: str = Field(default="", max_length=50)
    critical: bool = False
    contribution: float = Field(default=0, ge=0, le=100)


class Diagnosis(BaseModel):
    id: int | None = None
    score: float = Field(ge=0, le=100)
    risk_level: Literal["low", "moderate", "high", "critical"]
    findings: list[Finding] = Field(max_length=50)


class PlanRequest(BaseModel):
    diagnosis: Diagnosis
    context: str | None = Field(default=None, max_length=1000, description="Contexto livre da instituição (porte, público, território).")
    max_actions: int = Field(default=5, ge=1, le=10)
    use_cache: bool = True


class SearchRequest(BaseModel):
    query: str = Field(min_length=2, max_length=500)
    k: int = Field(default=5, ge=1, le=20)
