# EquiGov AI Service

Serviço interno (FastAPI) que transforma os achados de um diagnóstico em **sugestões de ação fundamentadas em documentos**, com cada citação verificada antes de chegar ao gestor.

```
achados do diagnóstico ──▶ busca híbrida no acervo ──▶ LLM (formato OpenAI) ──▶ verificador ──▶ WordPress
 (pergunta, resposta,       BM25 + embeddings locais     prompt versionado,       citações e      aprovação
  regra, contribuição)      RRF, trechos por página      JSON obrigatório         suporte textual  humana
```

## Componentes

| Módulo | Responsabilidade |
|---|---|
| `ingestion/extract.py` | Texto por página de PDF, HTML (incl. páginas do Planalto em windows-1252, ignorando texto revogado/riscado), TXT e Markdown |
| `ingestion/chunker.py` | Trechos de ~900 caracteres que nunca cruzam páginas e preferem cortar antes de `Art.` |
| `retrieval/bm25.py` | BM25 Okapi com normalização para português |
| `retrieval/embeddings.py` | Embeddings locais (`paraphrase-multilingual-MiniLM-L12-v2`, embutido na imagem); sem custo nem chamada externa |
| `retrieval/retriever.py` | Fusão BM25 + denso por Reciprocal Rank Fusion; degrada para BM25 se o modelo não carregar |
| `retrieval/store.py` | SQLite: documentos, trechos, embeddings e cache de planos |
| `generation/llm.py` | Cliente OpenAI-compatível com timeout, retries limitados (429/5xx/timeout) e `Retry-After`; `FakeLLM` determinístico |
| `generation/prompts.py` | Prompt versionado (`plan-v1`); trechos tratados como dados, não instruções |
| `generation/verifier.py` | Valida JSON, remove citações inexistentes, exige achado válido e mede suporte textual |
| `generation/planner.py` | Orquestra recuperação por achado, geração, reparo único de JSON, verificação e cache |
| `evaluation/` | hit@k e MRR da recuperação sobre casos anotados |

## Garantias

- **Nenhuma ação sem fonte real.** Ação que cita só fontes inexistentes ou não aponta achado do diagnóstico vai para `rejected` e não é sugerida.
- **Suporte textual mínimo.** Ação com fonte real, mas sem sobreposição de conteúdo com ela, é marcada `weak` para o revisor.
- **Rastreabilidade.** Cada ação traz os achados (com a regra de pontuação) e as fontes (documento, página, trecho, URL).
- **Aprovação humana.** `requires_human_approval: true` sempre; o WordPress só publica após aprovação do gestor.
- **Custo controlado.** Embeddings locais, cache por (prompt, modelo, achados, trechos), limite de tokens de saída e uma única tentativa de reparo.

## Endpoints

Documentação interativa: `http://localhost:8001/docs`. Todos exigem `X-Service-Token`, exceto `/health`.

| Método | Rota | Descrição |
|---|---|---|
| GET | `/health` | Estado do banco, LLM e modo de busca efetivo |
| GET / POST | `/v1/documents` | Lista / ingere (multipart: `file`, `title`, `source_url`) |
| DELETE | `/v1/documents/{id}` | Remove documento e invalida o cache |
| POST | `/v1/search` | Busca no acervo (`query`, `k`) |
| POST | `/v1/plans` | Gera plano a partir do relatório do diagnóstico |

## Configuração

| Variável | Padrão | |
|---|---|---|
| `AI_SERVICE_TOKEN` | — | Obrigatória, ≥ 16 caracteres |
| `LLM_PROVIDER` | `openai_compatible` | `fake` para demonstração/testes sem chave |
| `LLM_BASE_URL` | `https://api.groq.com/openai/v1` | Qualquer API compatível com OpenAI |
| `LLM_API_KEY` | — | Sem chave o serviço sobe (busca funciona) e `/health` indica `degraded` |
| `LLM_MODEL` | `openai/gpt-oss-120b` | |
| `RETRIEVAL_MODE` | `hybrid` | `bm25` desativa embeddings |

## Operação

```bash
docker compose exec ai-service python -m app.cli ingest /corpus   # acervo em ai-service/corpus
docker compose exec ai-service python -m app.cli eval             # qualidade da busca
docker compose exec ai-service python -m app.cli search "intérprete de libras"
docker compose run --rm --no-deps ai-service python -m pytest      # testes
```

Logs em JSON por linha (`request`, `document_ingested`, `plan_generated` com métricas de verificação, `llm_retry`, `llm_error`), com `x-request-id` propagado.
