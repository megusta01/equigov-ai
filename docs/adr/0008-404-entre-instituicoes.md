# 0008 — 404 (não 403) para registros de outra instituição

**Status:** aceita

## Contexto
Várias instituições usam a mesma instalação. Os IDs dos posts são sequenciais. Responder 403 a um ID de outra instituição confirmaria que aquele registro existe, permitindo enumerar diagnósticos alheios.

## Decisão
- O isolamento é aplicado no filtro `map_meta_cap`: `read_post`/`edit_post` de um registro de outra instituição viram `do_not_allow`. Vale para a REST API, o painel e o WP-CLI.
- Na API: sem acesso de leitura (outra instituição ou inexistente) → **404**; pode ler, mas não pode executar a ação → **403**; não autenticado → **401**.
- Listagens filtram por instituição **na consulta** (`meta_query`), não depois.
- Negar por padrão: usuário sem vínculo ou registro sem instituição não é acessível.

## Consequências
- (+) Não vaza existência nem quantidade de registros de outros órgãos.
- (+) Uma única regra (`InstitutionPolicy`, testada) aplicada em todos os pontos de entrada.
- (−) Mensagens de erro menos específicas para quem erra o ID; o log de auditoria preserva o diagnóstico.
