# 0007 — LLM por API compatível com OpenAI; LLM fake na CI

**Status:** aceita

## Contexto
Projeto sem orçamento. Provedores e limites de planos gratuitos mudam com frequência, e testes automatizados não podem depender de rede, chave nem respostas aleatórias.

## Decisão
- Um único cliente no formato **OpenAI Chat Completions**. Groq, Gemini, OpenRouter e Ollama aceitam esse formato; trocar de provedor é mudar `LLM_BASE_URL`, `LLM_API_KEY` e `LLM_MODEL`.
- Padrão: **Groq** (`openai/gpt-oss-120b`), com plano gratuito e sem uso dos dados para treino, segundo os termos do provedor.
- **`FakeLLM` determinístico** em testes, na CI e em demonstrações sem chave.
- Confiabilidade: timeout, novas tentativas só para erros transitórios (429, 5xx, timeout, `json_validate_failed`), `Retry-After` respeitado até 20 s, erros com código estável. O limite de tokens por minuto (413 do Groq) vira erro explicativo, sem repetição.
- Custo: cache por hash (prompt, modelo, achados, trechos), `max_tokens` limitado, uma única tentativa de reparo de JSON.

## Consequências
- (+) CI reprodutível e sem segredos; custo real medido em cerca de 3,2 mil tokens por plano.
- (+) Sem dependência de um fornecedor.
- (−) Limites do plano gratuito (8 mil tokens/minuto no modelo atual) restringem gerações seguidas; em produção, plano pago ou modelo com limite maior.
