# EquiGov AI

Plataforma de diagnóstico institucional e planejamento de políticas públicas inclusivas, com recomendações de IA **rastreáveis até a fonte** e **aprovação humana obrigatória**.

> Projeto independente de portfólio. Não tem vínculo com o IPEA nem reproduz código de terceiros.

## Status

| Módulo | Status |
|---|---|
| Ambiente Docker (WordPress + Nginx + MariaDB) | ✅ Dia 1 |
| Plugin `equigov-core`: CPTs, metadados, RBAC, isolamento por instituição, auditoria | ✅ Dia 1 |
| Motor de diagnóstico + API REST | ⏳ Dia 2 |
| Serviço de IA (FastAPI + RAG) | ⏳ Dia 3 |
| Integração, GitLab CI/CD, segurança | ⏳ Dia 4 |

## Rodando localmente

Requisitos: Docker + Docker Compose, Make, PHP 8.2+ e Composer (para os testes).

```bash
make install      # cria .env, sobe containers, instala WP, ativa plugin, cria dados de demo
open http://localhost:8080/wp-admin
curl http://localhost:8080/wp-json/equigov/v1/health
make test         # PHPUnit
```

Usuários de demonstração (senha `demo-equigov-2026`): `gestora.a`, `revisor.a`, `leitor.a` (Instituição A) e `gestor.b` (Instituição B).

## Arquitetura

```
Navegador ─▶ Nginx ─▶ WordPress (PHP-FPM) + plugin equigov-core ─▶ MariaDB
                                   │
                                   └──▶ ai-service (FastAPI + RAG)   [Dia 3]
```

Decisões registradas em [`docs/adr/`](docs/adr/) (Dia 5).
