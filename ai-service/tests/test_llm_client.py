from __future__ import annotations

import json

import httpx
import pytest

from app.generation.llm import LLMError, OpenAICompatibleClient

OK_BODY = {
    "model": "m-1",
    "choices": [{"message": {"content": '{"actions": []}'}}],
    "usage": {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15, "queue_time": 0.1},
}


def client(handler, retries=2):
    sleeps: list[float] = []
    c = OpenAICompatibleClient(
        "https://llm.test/v1", "k", "m", max_retries=retries, transport=httpx.MockTransport(handler), sleep=sleeps.append
    )
    return c, sleeps


def test_success_sends_json_mode_and_auth():
    seen = {}

    def handler(req: httpx.Request):
        seen["auth"] = req.headers["authorization"]
        seen["body"] = json.loads(req.content)
        return httpx.Response(200, json=OK_BODY)

    c, _ = client(handler)
    r = c.complete([{"role": "user", "content": "oi"}], max_tokens=50)
    assert r.text == '{"actions": []}' and r.attempts == 1
    assert r.usage == {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15}
    assert seen["auth"] == "Bearer k"
    assert seen["body"]["response_format"] == {"type": "json_object"}
    assert seen["body"]["max_tokens"] == 50


def test_retries_on_429_respecting_retry_after():
    calls = iter([httpx.Response(429, headers={"retry-after": "3"}), httpx.Response(200, json=OK_BODY)])
    c, sleeps = client(lambda req: next(calls))
    assert c.complete([], max_tokens=10).attempts == 2
    assert sleeps == [3.0]


def test_gives_up_after_max_retries_with_backoff():
    c, sleeps = client(lambda req: httpx.Response(503, text="busy"))
    with pytest.raises(LLMError) as e:
        c.complete([], max_tokens=10)
    assert e.value.code == "llm_error"
    assert sleeps == [1, 2]


def test_persistent_rate_limit_is_reported_as_such():
    c, _ = client(lambda req: httpx.Response(429), retries=0)
    with pytest.raises(LLMError) as e:
        c.complete([], max_tokens=10)
    assert e.value.code == "llm_rate_limited" and e.value.status == 503


def test_auth_error_is_not_retried():
    count = {"n": 0}

    def handler(req):
        count["n"] += 1
        return httpx.Response(401)

    c, sleeps = client(handler)
    with pytest.raises(LLMError) as e:
        c.complete([], max_tokens=10)
    assert e.value.code == "llm_auth" and count["n"] == 1 and sleeps == []


def test_timeout_becomes_504():
    def handler(req):
        raise httpx.ReadTimeout("slow", request=req)

    c, sleeps = client(handler)
    with pytest.raises(LLMError) as e:
        c.complete([], max_tokens=10)
    assert (e.value.code, e.value.status) == ("llm_timeout", 504)
    assert len(sleeps) == 2


def test_groq_tpm_413_is_explained_and_not_retried():
    body = {
        "error": {"message": "Request too large for model `openai/gpt-oss-120b` ... on tokens per minute (TPM): Limit 8000, Requested 9100"}
    }
    c, sleeps = client(lambda req: httpx.Response(413, json=body))
    with pytest.raises(LLMError) as e:
        c.complete([], max_tokens=10)
    assert (e.value.code, e.value.status) == ("llm_quota_too_small", 503)
    assert sleeps == []


def test_json_validate_failed_is_retried():
    bad = httpx.Response(400, json={"error": {"code": "json_validate_failed", "message": "Failed to generate JSON"}})
    calls = iter([bad, httpx.Response(200, json=OK_BODY)])
    c, _ = client(lambda req: next(calls))
    assert c.complete([], max_tokens=10).attempts == 2


def test_long_retry_after_is_capped():
    calls = iter([httpx.Response(429, headers={"retry-after": "45"}), httpx.Response(200, json=OK_BODY)])
    c, sleeps = client(lambda req: next(calls))
    c.complete([], max_tokens=10)
    assert sleeps == [20.0]


def test_unexpected_body():
    c, _ = client(lambda req: httpx.Response(200, json={"oops": True}))
    with pytest.raises(LLMError, match="formato inesperado"):
        c.complete([], max_tokens=10)


def test_missing_key():
    with pytest.raises(LLMError) as e:
        OpenAICompatibleClient("https://x", "", "m")
    assert e.value.code == "llm_not_configured"
