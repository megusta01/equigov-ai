# Runbook de operação

Comandos para Linux/macOS (`make`) e Windows (`.\tasks.ps1`). Execute na raiz do repositório.

## 1. Subir do zero

```bash
cp .env.example .env          # troque as senhas; gere AI_SERVICE_TOKEN aleatório (≥ 16 caracteres); cole LLM_API_KEY
make install                  # .\tasks.ps1 install — sobe containers, instala WP, ativa plugin, seed
make ai-ingest                # indexa as normas de ai-service/corpus
make smoke                    # valida API e fluxo do plano de ponta a ponta
```

Primeiro build: a imagem do serviço de IA baixa o modelo de embeddings (~220 MB).

## 2. Verificar a saúde

| Verificação | Comando | Esperado |
|---|---|---|
| Containers | `docker compose ps` | todos `running`/`healthy` |
| WordPress + banco + schema | `curl localhost:8080/wp-json/equigov/v1/health` | `"status":"ok"` |
| Serviço de IA | `curl localhost:8001/health` | `"status":"ok"`, `retrieval_mode_effective: hybrid`, `llm.error: null` |
| Acervo | idem, campo `library` | `documents` e `chunks` > 0 |
| Qualidade da busca | `make ai-eval` | hit@5 ≥ 0,8 |

`degraded` no serviço de IA significa **sem chave de LLM** (a busca funciona, a geração não) ou **modelo de embeddings ausente** (busca segue só em BM25).

## 3. Logs

```bash
docker compose logs -f --tail=100 ai-service      # JSON por linha
docker compose logs ai-service | grep '"event": "llm_'
docker compose exec wordpress tail -f /var/log/wordpress/debug.log
```

Correlação: o WordPress envia `X-Request-Id`; o mesmo id aparece no `debug.log` (`[equigov] ai_error request_id=…`) e no log do serviço (`"request_id"`).

Eventos úteis do serviço: `request`, `document_ingested`, `plan_generated` (com métricas de verificação e latência), `llm_retry`, `llm_error`, `embeddings_unavailable`.

## 4. Problemas conhecidos

| Sintoma para o gestor | Código | Causa provável | Ação |
|---|---|---|---|
| "biblioteca de referências está vazia" | `eg_ai_empty_library` | Acervo não ingerido | `make ai-ingest` |
| "provedor de IA atingiu o limite de uso" | `eg_ai_busy` | 429 persistente (limite por minuto/dia do plano) | Aguardar; verificar cota em console.groq.com |
| "pedido ficou maior que o limite por minuto" | `eg_ai_quota` | 413 do Groq: requisição > TPM do plano | Reduzir `MAX_SOURCES`/`LLM_MAX_TOKENS` ou trocar `LLM_MODEL` |
| "demorou demais" | `eg_ai_timeout` | LLM lento ou novas tentativas acima de 55 s | Tentar de novo: se terminou, vem do cache |
| "Falha de autenticação … (token)" | `eg_ai_auth` | `AI_SERVICE_TOKEN` diferente entre WP e serviço | Conferir `.env`, `docker compose up -d` |
| "não está configurado" | `eg_ai_not_configured` | `LLM_API_KEY` vazio ou URL/token ausentes | Preencher `.env`, recriar containers |
| "Já existe um plano em revisão" | `eg_plan_open` | Regra de negócio | Concluir ou rejeitar o plano aberto |
| Serviço não sobe | — | `AI_SERVICE_TOKEN` com menos de 16 caracteres | Ver `docker compose logs ai-service` |
| `/docs` do serviço sem resposta logo após restart | — | Carregando modelo e índice | Aguardar o `/health` responder |

## 5. Acervo de normas

```bash
# adicionar: copie o arquivo para ai-service/corpus/ e registre título/URL em sources.json
make ai-ingest                                               # idempotente; atualiza títulos se mudarem
docker compose exec ai-service python -m app.cli list
docker compose exec ai-service python -m app.cli delete 7    # remove e invalida o cache de planos
docker compose exec ai-service python -m app.cli search "intérprete de libras"
make ai-eval                                                 # sempre após mudar o acervo
```

## 6. Backup e restauração

```bash
mkdir -p backup
# Banco do WordPress (inclui wp_eg_responses e wp_eg_audit_log)
docker compose exec -T db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction equigov' > backup/equigov.sql
# Acervo e cache do serviço de IA (cópia consistente via API de backup do SQLite)
docker compose exec ai-service python -c "import sqlite3; sqlite3.connect('/data/equigov-ai.sqlite3').backup(sqlite3.connect('/data/backup.sqlite3'))"
docker compose cp ai-service:/data/backup.sqlite3 backup/equigov-ai.sqlite3

# Restaurar o banco
docker compose exec -T db sh -c 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" equigov' < backup/equigov.sql
```

O acervo também pode ser reconstruído do zero com `make ai-ingest`, porque os arquivos-fonte estão versionados.

## 7. Rotinas

- **Rotacionar o token de serviço:** gere um novo `AI_SERVICE_TOKEN` no `.env` e rode `docker compose up -d` (WordPress e serviço são recriados com o mesmo valor).
- **Mudar o prompt:** editar `ai-service/app/generation/prompts.py` **e** subir `PROMPT_VERSION`. A versão entra na chave do cache e na auditoria de cada plano.
- **Limpar o cache de planos:** `docker compose exec ai-service python -c "from app.retrieval.store import Store; Store('/data/equigov-ai.sqlite3').cache_clear()"`.
- **Novo questionário ou nova versão:** JSON em `wordpress/plugins/equigov-core/data/questionnaires/` com `version` maior e `make wp ARGS="equigov seed"`. Diagnósticos abertos mantêm o snapshot da versão anterior.
- **Reiniciar do zero (apaga dados):** `make reset` / `.\tasks.ps1 reset`.
