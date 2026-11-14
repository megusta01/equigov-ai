# EquiGov AI

[![CI](https://github.com/megusta01/equigov-ai/actions/workflows/ci.yml/badge.svg)](https://github.com/megusta01/equigov-ai/actions/workflows/ci.yml)

Plataforma de diagnóstico institucional e planejamento de políticas públicas inclusivas, com recomendações de IA **rastreáveis até a fonte** e **aprovação humana obrigatória**.

> Projeto independente de portfólio. Não tem vínculo com o IPEA nem reproduz código de terceiros.

## Status

| Módulo | Status |
|---|---|
| Ambiente Docker (WordPress + Nginx + MariaDB) | ✅ Dia 1 |
| Plugin `equigov-core`: CPTs, metadados, RBAC, isolamento por instituição, auditoria | ✅ Dia 1 |
| Motor de diagnóstico (perguntas condicionais, pontuação explicável) + API REST | ✅ Dia 2 |
| Serviço de IA: busca híbrida, planos com citações verificadas ([`ai-service/`](ai-service/README.md)) | ✅ Dia 3 |
| Plano de ação: sugestões da IA, revisão item a item, aprovação com separação de funções | ✅ Dia 4 |
| Interface no painel (WordPress) com fonte original ao lado de cada sugestão | ✅ Dia 4 |
| CI (GitHub Actions): unitários, integração, E2E no navegador, segredos e dependências | ✅ Dia 4 |

## Rodando localmente

Requisito: Docker (Docker Desktop no Windows/macOS). Os testes rodam em container.

**Windows (PowerShell):**

```powershell
.\tasks.ps1 install   # cria .env, sobe containers, instala WP, ativa plugin, cria dados de demo
.\tasks.ps1 test      # PHPUnit (44 testes do domínio)
.\tasks.ps1 smoke     # integração: API + banco reais, incluindo isolamento entre instituições
.\tasks.ps1 ai-ingest # indexa os documentos de ai-service/corpus
.\tasks.ps1 ai-test   # testes do serviço de IA
```

**Linux/macOS:** `make install`, `make test`, `make smoke`.

## API

Namespace `equigov/v1`. Autenticação, erros, regra de pontuação e exemplos em [`docs/api.md`](docs/api.md).

| Método | Rota | Quem |
|---|---|---|
| GET | `/health` | público |
| GET | `/questionnaires`, `/questionnaires/{id}` | leitor+ |
| GET / POST | `/diagnoses` | leitor+ / gestor |
| GET | `/diagnoses/{id}`, `/diagnoses/{id}/report` | leitor+ da mesma instituição |
| PATCH | `/diagnoses/{id}/answers` | gestor da mesma instituição |
| POST | `/diagnoses/{id}/submit` | gestor da mesma instituição |

Depois acesse `http://localhost:8080/wp-admin` e `http://localhost:8080/wp-json/equigov/v1/health`.

Usuários de demonstração (senha `demo-equigov-2026`): `gestora.a`, `revisor.a`, `leitor.a` (Instituição A) e `gestor.b` (Instituição B).

## Fluxo

```
Gestor responde o questionário ─▶ pontuação determinística e explicável (sem IA)
        │
        ▼
Gestor pede sugestões ─▶ serviço de IA busca normas por achado ─▶ LLM propõe ─▶ verificador confere citações
        │
        ▼
Revisor decide cada sugestão (aceitar · editar · rejeitar), vendo o trecho original da norma ao lado
        │
        ▼
Outro gestor aprova ou rejeita (quem revisou não pode aprovar) ─▶ plano final com origem de cada ação
```

Tudo registrado na trilha de auditoria: geração (modelo, versão do prompt, métricas de verificação, tokens), cada decisão, conclusão da revisão e aprovação.

## Testes

| Camada | Ferramenta | O que cobre |
|---|---|---|
| Domínio PHP | PHPUnit (58) | Questionário, condições, validação, pontuação, máquina de estados do plano |
| Serviço de IA | pytest (57) | Ingestão, busca, cliente LLM, verificador, planner, API, CLI |
| Integração | WP-CLI smoke | API real + MariaDB: isolamento entre instituições, RBAC por etapa, separação de funções, auditoria |
| Interface | Playwright | Navegador real: responder, enviar, gerar plano, revisar, aprovar |

## Arquitetura

```
Navegador ─▶ Nginx ─▶ WordPress (PHP-FPM) + plugin equigov-core ─▶ MariaDB
                                   │
                                   └──▶ ai-service (FastAPI + RAG) ─▶ LLM (API compatível OpenAI)
```

Decisões registradas em [`docs/adr/`](docs/adr/) (Dia 5).
