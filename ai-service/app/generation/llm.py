"""Cliente de LLM no formato OpenAI Chat Completions (Groq, Gemini, OpenRouter, Ollama...).

Confiabilidade: timeout explícito, novas tentativas limitadas apenas para erros transitórios
(429, 5xx, timeout), backoff exponencial respeitando Retry-After. Erros viram LLMError com código estável.
"""

from __future__ import annotations

import json
import logging
import re
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Protocol

import httpx

log = logging.getLogger(__name__)

RETRYABLE = {408, 409, 429, 500, 502, 503, 504}


class LLMError(Exception):
    def __init__(self, code: str, message: str, status: int = 502) -> None:
        super().__init__(message)
        self.code, self.status = code, status


@dataclass
class LLMResult:
    text: str
    model: str
    latency_ms: int
    usage: dict = field(default_factory=dict)
    attempts: int = 1


class LLMClient(Protocol):
    provider: str
    model: str

    def complete(self, messages: list[dict], *, max_tokens: int, json_mode: bool = True) -> LLMResult: ...


class OpenAICompatibleClient:
    provider = "openai_compatible"

    def __init__(
        self,
        base_url: str,
        api_key: str,
        model: str,
        *,
        timeout_s: float = 45,
        max_retries: int = 2,
        temperature: float = 0.2,
        transport: httpx.BaseTransport | None = None,
        sleep: Callable[[float], None] = time.sleep,
    ) -> None:
        if not api_key:
            raise LLMError("llm_not_configured", "LLM_API_KEY não definido.", 503)
        self.model, self.max_retries, self.temperature, self._sleep = model, max_retries, temperature, sleep
        self._http = httpx.Client(
            base_url=base_url.rstrip("/"),
            headers={"Authorization": f"Bearer {api_key}"},
            timeout=httpx.Timeout(timeout_s, connect=5.0),
            transport=transport,
        )

    def complete(self, messages: list[dict], *, max_tokens: int, json_mode: bool = True) -> LLMResult:
        body: dict = {"model": self.model, "messages": messages, "temperature": self.temperature, "max_tokens": max_tokens}
        if json_mode:
            body["response_format"] = {"type": "json_object"}

        started = time.monotonic()
        for attempt in range(1, self.max_retries + 2):
            try:
                resp = self._http.post("/chat/completions", json=body)
            except httpx.TimeoutException as e:
                if attempt > self.max_retries:
                    raise LLMError("llm_timeout", "O provedor de IA não respondeu a tempo.", 504) from e
                self._backoff(attempt, None)
                continue
            except httpx.HTTPError as e:
                if attempt > self.max_retries:
                    raise LLMError("llm_unreachable", f"Falha de conexão com o provedor de IA: {e}", 502) from e
                self._backoff(attempt, None)
                continue

            if resp.status_code == 200:
                return self._parse(resp, started, attempt)
            if resp.status_code in (401, 403):
                raise LLMError("llm_auth", "Chave do provedor de IA inválida ou sem permissão.", 502)
            if resp.status_code in RETRYABLE and attempt <= self.max_retries:
                log.warning("llm_retry", extra={"status": resp.status_code, "attempt": attempt})
                self._backoff(attempt, resp.headers.get("retry-after"))
                continue
            code = "llm_rate_limited" if resp.status_code == 429 else "llm_error"
            raise LLMError(
                code, f"Provedor de IA respondeu {resp.status_code}: {resp.text[:300]}", 503 if code == "llm_rate_limited" else 502
            )

        raise LLMError("llm_error", "Falha inesperada no cliente de IA.")  # pragma: no cover

    def _backoff(self, attempt: int, retry_after: str | None) -> None:
        delay = min(2 ** (attempt - 1), 8.0)
        if retry_after:
            try:
                delay = min(float(retry_after), 10.0)
            except ValueError:
                pass
        self._sleep(delay)

    def _parse(self, resp: httpx.Response, started: float, attempt: int) -> LLMResult:
        try:
            data = resp.json()
            text = data["choices"][0]["message"]["content"] or ""
        except (ValueError, KeyError, IndexError, TypeError) as e:
            raise LLMError("llm_bad_response", f"Resposta do provedor em formato inesperado: {e}") from e
        return LLMResult(
            text=text,
            model=data.get("model", self.model),
            latency_ms=int((time.monotonic() - started) * 1000),
            usage={k: v for k, v in (data.get("usage") or {}).items() if k in ("prompt_tokens", "completion_tokens", "total_tokens")},
            attempts=attempt,
        )


class FakeLLM:
    """LLM determinístico para testes, CI e demonstração sem chave.

    Lê o JSON de entrada que o planner coloca na mensagem e cria uma ação por achado,
    citando a fonte recuperada para ele. Não "inventa" nada: serve para exercitar o fluxo inteiro.
    """

    provider = "fake"
    model = "fake-deterministic-v1"

    def complete(self, messages: list[dict], *, max_tokens: int, json_mode: bool = True) -> LLMResult:
        payload = _extract_payload(messages[-1]["content"])
        actions = []
        for f in payload.get("findings", [])[: payload.get("max_actions", 5)]:
            sources = f.get("retrieved_sources") or []
            if not sources:
                continue
            src = next(s for s in payload["sources"] if s["id"] == sources[0])
            actions.append(
                {
                    "title": f"Enfrentar: {f['question_text'][:80]}",
                    "description": f"Medida baseada no trecho {src['id']}: {src['text'][:220]}",
                    "finding_keys": [f["key"]],
                    "source_ids": [src["id"]],
                    "priority": "alta" if f.get("critical") else "media",
                    "deadline_days": 90 if f.get("critical") else 180,
                    "indicator": "Percentual de atendimentos sem a barreira identificada",
                    "rationale": f"{f['message']} Fundamentação: {src['text'][:200]}",
                }
            )
        return LLMResult(text=json.dumps({"actions": actions}, ensure_ascii=False), model=self.model, latency_ms=0)


def _extract_payload(content: str) -> dict:
    m = re.search(r"<entrada>\s*(\{.*\})\s*</entrada>", content, re.S)
    return json.loads(m.group(1)) if m else {}
