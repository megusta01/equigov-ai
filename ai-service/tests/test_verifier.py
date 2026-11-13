from __future__ import annotations

import pytest

from app.generation.verifier import ParseError, parse_plan, verify

SOURCES = {
    "S1": "A unidade deve dispor de profissional capacitado em Libras ou central de interpretação por vídeo para pessoas surdas.",
    "S2": "Nenhum serviço deve ser oferecido exclusivamente por canal digital; deve existir alternativa presencial.",
}
KEYS = {"libras", "canais"}


def action(**kw):
    base = {
        "title": "Contratar central de interpretação em Libras",
        "description": "Disponibilizar central de interpretação por vídeo em Libras para atendimento de pessoas surdas.",
        "finding_keys": ["libras"],
        "source_ids": ["S1"],
        "priority": "alta",
        "deadline_days": 90,
        "indicator": "Atendimentos em Libras por mês",
        "rationale": "O trecho exige profissional capacitado em Libras ou interpretação por vídeo.",
    }
    base.update(kw)
    return base


def run(*actions):
    import json

    return verify(parse_plan(json.dumps({"actions": list(actions)})), finding_keys=KEYS, sources=SOURCES, min_support=0.12)


def test_grounded_action():
    a = run(action()).actions[0]
    assert a.status == "grounded"
    assert a.support["S1"] >= 0.3


def test_hallucinated_citation_is_removed_and_counted():
    report = run(action(source_ids=["S1", "S9"]))
    a = report.actions[0]
    assert a.valid_sources == ["S1"] and a.invalid_sources == ["S9"]
    assert a.status == "grounded"
    assert report.summary()["invalid_citations"] == 1
    assert report.summary()["citation_validity"] == 0.5


def test_action_citing_only_invented_sources_is_rejected():
    a = run(action(source_ids=["S7"])).actions[0]
    assert a.status == "rejected"
    assert "sem fonte válida" in a.reasons


def test_action_without_valid_finding_is_rejected():
    a = run(action(finding_keys=["achado_inventado"])).actions[0]
    assert a.status == "rejected"


def test_unsupported_content_is_marked_weak():
    # Cita uma fonte real, mas o conteúdo não tem relação com ela.
    a = run(
        action(
            title="Reformar o estacionamento",
            description="Pintar vagas e trocar o asfalto do estacionamento municipal.",
            rationale="Melhorar o pavimento.",
            source_ids=["S2"],
            finding_keys=["canais"],
        )
    ).actions[0]
    assert a.status == "weak"


def test_parse_accepts_code_fences_and_normalizes_priority():
    plan = parse_plan('```json\n{"actions": [' + __import__("json").dumps(action(priority="Média")) + "]}\n```")
    assert plan.actions[0].priority == "media"


@pytest.mark.parametrize("text", ["não é json", '{"actions": [{"title": "x"}]}', '{"actions": "nope"}'])
def test_parse_errors(text):
    with pytest.raises(ParseError):
        parse_plan(text)


def test_duplicate_citations_are_deduplicated():
    a = run(action(source_ids=["S1", "S1"], finding_keys=["libras", "libras"])).actions[0]
    assert a.valid_sources == ["S1"] and a.valid_findings == ["libras"]
