from __future__ import annotations

from fastapi.testclient import TestClient

from app.main import create_app

from .conftest import FIXTURES, TOKEN, diagnosis, make_settings


def test_health_is_public(client):
    r = client.get("/health", headers={"X-Service-Token": ""})
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "ok"
    assert body["library"]["documents"] == 3


def test_everything_else_requires_service_token(client):
    for method, path in [("get", "/v1/documents"), ("post", "/v1/search"), ("post", "/v1/plans")]:
        assert getattr(client, method)(path, headers={"X-Service-Token": "errado"}).status_code == 401


def test_upload_duplicate_and_validation(client):
    data = (FIXTURES / "nota-dados.md").read_bytes()
    assert client.post("/v1/documents", files={"file": ("x.md", data)}).json()["status"] == "duplicate"

    r = client.post("/v1/documents", files={"file": ("x.exe", b"MZ")})
    assert r.status_code == 422 and r.json()["detail"]["code"] == "extraction_failed"

    r = client.post("/v1/documents", files={"file": ("a.md", b"texto")}, data={"source_url": "javascript:alert(1)"})
    assert r.status_code == 422


def test_upload_size_limit(tmp_path):
    app = create_app(make_settings(tmp_path, max_upload_mb=1))
    c = TestClient(app, headers={"X-Service-Token": TOKEN})
    r = c.post("/v1/documents", files={"file": ("big.txt", b"a" * (1024 * 1024 + 10))})
    assert r.status_code == 413


def test_search(client):
    r = client.post("/v1/search", json={"query": "guichê preferencial para idosos", "k": 2})
    assert r.status_code == 200
    assert r.json()[0]["document"] == "guia-atendimento"


def test_generate_plan(client):
    r = client.post("/v1/plans", json={"diagnosis": diagnosis(), "max_actions": 3})
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["actions"] and body["meta"]["provider"] == "fake"
    assert r.headers["x-request-id"]


def test_plan_input_validation(client):
    bad = diagnosis()
    bad["findings"][0]["question_key"] = "DROP TABLE"
    assert client.post("/v1/plans", json={"diagnosis": bad}).status_code == 422
    assert client.post("/v1/plans", json={"diagnosis": diagnosis(), "max_actions": 50}).status_code == 422


def test_service_starts_without_llm_key_and_reports_it(tmp_path):
    app = create_app(make_settings(tmp_path, llm_provider="openai_compatible", llm_api_key=""))
    c = TestClient(app, headers={"X-Service-Token": TOKEN})
    health = c.get("/health").json()
    assert health["status"] == "degraded" and health["llm"]["error"] == "llm_not_configured"

    c.post("/v1/documents", files={"file": ("g.md", (FIXTURES / "guia-atendimento.md").read_bytes())})
    assert c.post("/v1/search", json={"query": "Libras"}).status_code == 200  # busca segue funcionando
    r = c.post("/v1/plans", json={"diagnosis": diagnosis()})
    assert r.status_code == 503 and r.json()["code"] == "llm_not_configured"


def test_delete_document(client):
    doc_id = client.get("/v1/documents").json()[0]["id"]
    assert client.delete(f"/v1/documents/{doc_id}").status_code == 204
    assert client.delete(f"/v1/documents/{doc_id}").status_code == 404


def test_weak_token_is_refused_at_startup(tmp_path):
    import pytest

    with pytest.raises(RuntimeError, match="AI_SERVICE_TOKEN"):
        create_app(make_settings(tmp_path, service_token="curto"))
