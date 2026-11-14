"""Teste ponta a ponta no navegador (Playwright).

Fluxo: gestora cria e responde um diagnóstico → envia → gera plano com IA →
revisor decide cada sugestão (edita a primeira) → conclui → gestora aprova.
Verifica também a pergunta condicional e a separação de funções na interface.

Pré-requisitos: WordPress em BASE_URL com `wp equigov seed` e serviço de IA configurado.

    pip install playwright && playwright install chromium
    BASE_URL=http://localhost:8080 python tests/e2e/admin_flow.py

Variáveis: BASE_URL, CHROME_PATH (usar um Chrome já instalado), SHOTS_DIR (capturas de tela).
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

from playwright.sync_api import expect, sync_playwright

BASE = os.environ.get("BASE_URL", "http://localhost:8080").rstrip("/")
SHOTS = Path(os.environ.get("SHOTS_DIR", "e2e-screenshots"))
PASSWORD = "demo-equigov-2026"  # senha dos usuários de demonstração do seed


def login(page, user: str) -> None:
    page.context.clear_cookies()
    page.goto(f"{BASE}/wp-login.php")
    page.fill("#user_login", user)
    page.fill("#user_pass", PASSWORD)
    page.click("#wp-submit")
    page.wait_for_url(re.compile(r"wp-admin"))
    page.goto(f"{BASE}/wp-admin/admin.php?page=equigov")
    expect(page.get_by_role("heading", level=2).first).to_be_visible()


def main() -> int:
    SHOTS.mkdir(parents=True, exist_ok=True)
    errors: list[str] = []

    with sync_playwright() as p:
        launch = {"args": ["--no-sandbox"]}
        if os.environ.get("CHROME_PATH"):
            launch["executable_path"] = os.environ["CHROME_PATH"]
        browser = p.chromium.launch(**launch)
        page = browser.new_page(viewport={"width": 1366, "height": 900}, locale="pt-BR")
        # Só erros da tela EquiGov: o painel inicial do WordPress tem widgets que dependem de internet.
        page.on("pageerror", lambda e: "page=equigov" in page.url and errors.append(f"pageerror: {e}"))
        page.on("response", lambda r: r.status >= 500 and "equigov" in r.url and errors.append(f"{r.status} {r.url}"))

        # 1. Gestora cria e responde
        login(page, "gestora.a")
        page.select_option("#eg-q", label="Diagnóstico de acesso inclusivo a serviços públicos (v1)")
        page.fill("#eg-title", "Unidade Centro — E2E")
        page.click("text=Iniciar diagnóstico")
        expect(page.get_by_role("heading", name="Unidade Centro — E2E")).to_be_visible()

        page.check("label:text-is('Somente digital')")
        expect(page.get_by_text("Quais recursos de acessibilidade física")).to_be_hidden()
        expect(page.get_by_text("já foi avaliado quanto à acessibilidade")).to_be_visible()

        for label in ["Nunca foi avaliado", "Não há recurso", "Parcialmente", "Apenas de forma improvisada", "Gênero",
                      "Já houve, sem periodicidade", "Apenas ouvidoria genérica"]:
            page.check(f"label:text-is('{label}')")
        page.check("fieldset:has-text('identificar desigualdades') label:text-is('Nunca')")
        page.screenshot(path=SHOTS / "1-formulario.png")

        page.click("text=Enviar e calcular")
        expect(page.get_by_text("Nível de risco:")).to_be_visible(timeout=15000)
        page.screenshot(path=SHOTS / "2-relatorio.png", full_page=True)

        # 2. Gera plano
        page.fill("#eg-ctx", "Município de médio porte, duas unidades de atendimento.")
        page.click("text=Gerar sugestões com IA")
        expect(page.locator(".eg-badge.eg-status-pending_review")).to_be_visible(timeout=60000)
        plan_url = page.url

        # 3. Revisor decide cada sugestão
        login(page, "revisor.a")
        page.goto(plan_url)
        expect(page.locator("article.eg-item").first).to_be_visible()
        expect(page.get_by_role("button", name="Aprovar plano")).to_have_count(0)  # revisor não aprova
        count = page.locator("article.eg-item").count()
        for i in range(count):
            card = page.locator("article.eg-item").nth(i)
            if i == 0:
                card.get_by_label("Aceitar com edição").check()
                card.get_by_label("Título").fill("Central municipal de interpretação em Libras")
            else:
                card.get_by_label("Aceitar", exact=True).check()
            card.get_by_label("Observação (obrigatória para rejeitar)").fill("Conferido com o trecho citado.")
            card.get_by_role("button", name="Registrar decisão").click()
            expect(page.locator("article.eg-item").nth(i).locator(".eg-decision")).to_be_visible()
        page.screenshot(path=SHOTS / "3-revisao.png", full_page=True)
        page.click("text=Concluir revisão")
        expect(page.locator(".eg-badge.eg-status-reviewed")).to_be_visible()

        # 4. Gestora aprova
        login(page, "gestora.a")
        page.goto(plan_url)
        page.get_by_role("button", name="Aprovar plano").click()
        expect(page.get_by_role("heading", name="Plano aprovado")).to_be_visible()
        expect(page.locator(".eg-footer table").get_by_text("Central municipal de interpretação em Libras")).to_be_visible()
        page.screenshot(path=SHOTS / "4-aprovado.png", full_page=True)

        browser.close()

    print(f"Sugestões revisadas: {count}")
    if errors:
        print("Erros:", *errors, sep="\n  ")
        return 1
    print("E2E ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
