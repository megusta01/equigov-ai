# API REST — `equigov/v1`

Base: `/wp-json/equigov/v1`. Todas as respostas são JSON.

## Autenticação

- **Front-end (mesmo domínio):** cookie de sessão do WordPress + cabeçalho `X-WP-Nonce` (nonce `wp_rest`).
- **Clientes externos:** [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) via HTTP Basic. Em produção, apenas sobre HTTPS.

## Autorização

Duas regras se combinam em todos os endpoints:

1. **Capacidade (RBAC):** cada papel tem capacidades `eg_*` próprias, independentes dos papéis nativos do WordPress.

   | Papel | Ler diagnósticos | Responder/enviar | Revisar planos | Aprovar planos | Gerir instituições |
   |---|:-:|:-:|:-:|:-:|:-:|
   | Leitor | ✔ | | | | |
   | Revisor | ✔ | | ✔ | | |
   | Gestor | ✔ | ✔ | ✔ | ✔ | |
   | Admin da plataforma | ✔ | ✔ | ✔ | ✔ | ✔ |

2. **Escopo institucional:** usuários só acessam registros da própria instituição. A regra é aplicada no filtro `map_meta_cap`, portanto vale também para o painel administrativo e o WP-CLI.

## Erros

Formato padrão do WordPress: `{ "code": "...", "message": "...", "data": { "status": 422, ... } }`.

| Status | Código | Quando |
|---|---|---|
| 401 | `rest_not_logged_in` | Requisição sem autenticação |
| 403 | `rest_forbidden` | Autenticado, sem a capacidade necessária |
| 404 | `eg_not_found` | Registro inexistente **ou de outra instituição** (a API não confirma a existência de registros fora do escopo) |
| 409 | `eg_locked`, `eg_not_scored` | Operação incompatível com o estado do diagnóstico |
| 422 | `eg_invalid_answers` | Respostas inválidas; `data.fields` traz o erro por pergunta |
| 422 | `eg_incomplete` | Envio sem todas as obrigatórias visíveis; `data.missing` lista as chaves |
| 409 | `eg_plan_open` | Já existe plano não finalizado (aguardando revisão, em revisão ou revisado) para o diagnóstico |
| 409 | `eg_plan_busy` | Outra geração de plano para o mesmo diagnóstico está em andamento |
| 403 | `eg_segregation` | Quem concluiu a revisão ou decidiu algum item tentou aprovar |
| 422 | `eg_note_required` / `eg_review_incomplete` | Rejeição ou aceite de sugestão fraca sem justificativa / itens sem decisão |
| 502–504 | `eg_ai_*` | Falhas do serviço de IA, com mensagem para o gestor (ver runbook) |

## Endpoints

### `GET /health`
Público. `200` com `{"status":"ok","checks":{"database":true,"schema":true}}` ou `503` com `"degraded"`.

### `GET /questionnaires`
Lista questionários publicados: `[{ "id", "title", "version" }]`.

### `GET /questionnaires/{id}`
Schema público do questionário: dimensões, perguntas, opções e condições `show_if`. **Não** inclui riscos, pesos dos achados nem textos de achados.

### `GET /diagnoses`
Diagnósticos da instituição do usuário, mais recentes primeiro.
Parâmetros: `page`, `per_page` (1–100), `status` (`draft`, `in_progress`, `scored`).
Cabeçalhos: `X-WP-Total`, `X-WP-TotalPages`.

### `POST /diagnoses`
Corpo: `{ "questionnaire_id": 4, "title": "opcional" }`. Resposta `201` com `Location`.
O schema do questionário é copiado para o diagnóstico (snapshot): alterações posteriores no questionário não afetam diagnósticos já criados.

### `GET /diagnoses/{id}`
Dados do diagnóstico, respostas salvas, progresso (`visible_questions`, `required_missing`, `percent`, `can_submit`) e o schema do snapshot.

### `PATCH /diagnoses/{id}/answers`
Salvamento parcial. Corpo: `{ "answers": { "canais": "ambos", "coleta_desagregada": ["raca_cor", "genero"] } }`.
`single_choice` recebe string; `multi_choice` recebe lista sem repetição (lista vazia é válida). O lote é gravado em transação. Retorna o progresso atualizado.

### `POST /diagnoses/{id}/submit`
Valida o conjunto, descarta respostas de perguntas que ficaram ocultas, calcula a pontuação e trava o diagnóstico. Retorna o resultado (ver abaixo).

### `GET /diagnoses/{id}/report`
Resultado completo de um diagnóstico concluído.

### Plano de ação

| Método | Rota | Quem | O que faz |
|---|---|---|---|
| POST | `/diagnoses/{id}/action-plans` | gestor | Gera sugestões com IA (`context` opcional, filtrado de dados pessoais; `max_actions` 1–10) |
| GET | `/diagnoses/{id}/action-plans` | leitor+ | Planos do diagnóstico |
| GET | `/action-plans/{id}` | leitor+ | Itens (texto original da IA, versão final, decisão), fontes, métricas da IA |
| PATCH | `/action-plans/{id}/items/{a1…}` | revisor | `decision`: `accepted` · `edited` (com `title`, `description`, `priority`, `deadline_days`, `indicator`) · `rejected`; `note` |
| POST | `/action-plans/{id}/complete-review` | revisor | Exige todos os itens decididos |
| POST | `/action-plans/{id}/approve` | gestor que não participou da revisão | Finaliza o plano |
| POST | `/action-plans/{id}/reject` | gestor | `reason` com 10+ caracteres |

## Resultado do diagnóstico

```json
{
  "score": 81.0,
  "risk_level": "critical",
  "risk_label": "Crítico",
  "critical_count": 1,
  "rule_version": "scoring-v1",
  "dimensions": {
    "comunicacao": { "label": "Comunicação e linguagem", "score": 81.7, "answered": true }
  },
  "findings": [
    {
      "question_key": "libras",
      "question_text": "Como a unidade atende pessoas surdas usuárias de Libras?",
      "answer": "Não há recurso",
      "rule": "Opção \"Não há recurso\" tem risco 1.00 (peso 3).",
      "message": "Sem atendimento em Libras, pessoas surdas dependem de terceiros...",
      "reference": "Lei nº 10.436/2002 e Decreto nº 5.626/2005",
      "dimension": "comunicacao",
      "weight": 3,
      "risk": 1.0,
      "critical": true,
      "contribution": 15.0
    }
  ]
}
```

### Regra de pontuação (`scoring-v1`)

- Risco por pergunta: `single_choice` usa o risco da opção escolhida; `multi_choice` usa `1 − práticas adotadas / total`.
- Pontuação (geral e por dimensão): `Σ(peso × risco) / Σ(peso) × 100`, considerando apenas perguntas visíveis.
- Nível: `< 25` baixo, `< 50` moderado, `< 75` alto, `≥ 75` crítico. Qualquer resposta marcada como crítica eleva o nível para, no mínimo, alto.
- Achados: perguntas com risco ≥ 0,5 ou resposta crítica, ordenados com críticos primeiro e depois por `contribution` (pontos da nota geral explicados pelo item).

## Exemplo

```bash
AUTH="gestora.a:<application-password>"
API=http://localhost:8080/wp-json/equigov/v1

curl -u "$AUTH" $API/questionnaires
curl -u "$AUTH" -X POST  $API/diagnoses            -H 'Content-Type: application/json' -d '{"questionnaire_id": 4}'
curl -u "$AUTH" -X PATCH $API/diagnoses/5/answers  -H 'Content-Type: application/json' -d '{"answers": {"canais": "ambos"}}'
curl -u "$AUTH" -X POST  $API/diagnoses/5/submit
curl -u "$AUTH"          $API/diagnoses/5/report
```
