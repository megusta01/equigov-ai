# Estudos de caso: falhas reais, causas e correções

Falhas encontradas durante o desenvolvimento, todas com teste de regressão. Ficam registradas porque mostram como o sistema foi validado, e não só construído.

## 1. Servidor de IA não enxergava documentos recém-ingeridos

**Sintoma.** Depois de `ai-ingest`, a geração de planos respondia `empty_library` ("biblioteca vazia"), embora a CLI listasse os documentos.

**Investigação.** A CLI e o servidor são **processos diferentes** que compartilham o mesmo SQLite. O servidor montava o índice (BM25 e matriz de embeddings) **só ao iniciar**; documentos gravados depois por outro processo não entravam no índice em memória.

**Correção.** `Store.signature()` (contagem, maior id e trechos sem embedding) é comparada a cada busca; se mudou, o índice é reconstruído (`Retriever.refresh_if_stale`).

**Regressão.** `test_server_sees_documents_ingested_by_another_process`: duas conexões ao mesmo arquivo; uma ingere, a outra precisa encontrar o documento sem reiniciar.

**Achado relacionado na mesma investigação.** As 6 leis foram salvas como `.html` e o manifesto listava `.htm`. Os títulos viraram nomes de arquivo e a avaliação deu hit@5 = 0. Correção: o manifesto casa pelo nome sem extensão e sem diferenciar maiúsculas, `README*` é ignorado e reingerir atualiza título e URL sem reindexar (`test_cli.py`). Resultado depois: hit@1 = 0,875.

## 2. A IA generalizou uma norma além do seu escopo

**Sintoma.** Plano real (Groq, `plan-v1`) com 3 ações, todas `grounded` e 100% das citações válidas. Na **leitura humana**, a justificativa dizia que o Decreto nº 5.626/2005 exige intérpretes "nos serviços públicos", mas os trechos citados tratavam de **saúde (SUS) e educação**. A ação também trazia "carga horária mínima de 40 horas", que não está em nenhuma fonte.

**Investigação.** O verificador mede sobreposição de termos entre ação e trecho: pega citação inventada e conteúdo sem relação, mas não percebe generalização de escopo. Havia ainda uma causa técnica: os achados chegaram ao serviço com acentos corrompidos (`Get-Content` do PowerShell 5.1 lê UTF-8 sem BOM como ANSI), o que piorou a busca.

**Correção.**
- Prompt `plan-v2`: respeitar o escopo do trecho e dizê-lo na justificativa; quantidades que não estão nas normas devem vir marcadas como "Sugestão:"; citar artigo e inciso; distribuir as ações entre os achados.
- Recuperação com 4 trechos por achado.
- Respostas do serviço com `charset=utf-8` explícito.

**Resultado.** A segunda execução usou o art. 26 do Decreto (administração pública em geral) e o percentual de 5% de servidores capacitados, que **vem da norma**.

**Lição registrada no ADR 0006:** a verificação automática é necessária, mas não suficiente. A aprovação humana, com o trecho original ao lado da justificativa, é parte do desenho, não um enfeite.

## 3. Seções do formulário apareciam como `[object HTMLElement]`

**Sintoma.** No teste E2E com navegador, o formulário do diagnóstico não mostrava as perguntas.

**Causa.** `render(...nodes)` repassava listas de elementos para `replaceChildren`, que converte arrays em texto. Valores `null` virariam o texto "null".

**Correção.** Achatar e filtrar antes de renderizar. A checagem de sintaxe (`node --check`) não pega esse tipo de erro: só o teste no navegador pegou.

**Regressão.** `tests/e2e/admin_flow.py`, executado na CI em Chromium.

## 4. Datas três horas à frente

**Sintoma.** No mesmo plano, "Gerado em 19:48" e "Decisão em 16:48".

**Causa.** `mysql_to_rfc3339()` do WordPress devolve a data GMT **sem fuso**; o navegador a interpretou como hora local (UTC−3).

**Correção.** `Support\Time::gmtToIso()`, que produz `2026-11-13T19:48:17+00:00`. **Regressão:** `TimeTest`.

## 5. Segunda geração seguida falhava no provedor

**Sintoma.** No teste de integração com o Groq real, a primeira geração passou e a segunda, cerca de 10 s depois, voltou como `llm_error` genérico.

**Investigação.** No plano gratuito, o modelo tem limite de 8 mil tokens por minuto. O provedor responde **413** quando a requisição excede esse limite e **429** quando o limite é atingido pela soma de requisições; também pode responder **400 `json_validate_failed`** em modo JSON. O cliente tratava os três como erro genérico.

**Correção.** 413 por tokens/minuto vira `llm_quota_too_small` (mensagem clara, sem repetição inútil); `json_validate_failed` é repetido; a espera do `Retry-After` vai até 20 s. No teste de integração, a segunda geração usa os mesmos parâmetros e sai do cache, sem gastar cota. **Regressão:** três testes em `test_llm_client.py`.

## 6. Condição de corrida no próprio teste E2E

**Sintoma.** O teste concluía a revisão com itens pendentes (422).

**Causa.** Ele contava os cartões logo após a navegação, antes de a página terminar de renderizar, e encontrava zero.

**Correção.** Esperar por um elemento (`expect(...).to_be_visible()`), nunca por tempo fixo.

## 7. Revisão externa encontrou quatro brechas no fluxo do plano

**Contexto.** Submeti o projeto a uma revisão independente (outro assistente de IA, sem acesso ao histórico desta implementação). Ela apontou quatro problemas; conferi cada um no código e todos procediam.

| Brecha | Causa | Correção | Teste |
|---|---|---|---|
| Era possível gerar outro plano enquanto um plano revisado aguardava aprovação | A checagem de "plano aberto" considerava só `pending_review` e `in_review` | `PlanWorkflow::OPEN` inclui `reviewed` | `testReviewedPlanIsStillOpen`, smoke |
| Quem decidiu itens podia aprovar, se outra pessoa clicasse em "concluir" | A separação de funções comparava o aprovador só com quem concluiu | `participants()`: todos que decidiram itens + quem concluiu | `testWhoeverDecidedAnyItemCannotApprove`, smoke com revisão compartilhada |
| Duas requisições simultâneas podiam gerar dois planos | Checagem e gravação sem exclusão mútua | `GET_LOCK` por diagnóstico; a segunda recebe 409 `eg_plan_busy` | Smoke: outra conexão segura a trava e a geração é recusada |
| A documentação dizia que nenhum dado pessoal ia para a IA, mas o contexto é texto livre | Promessa maior que o código | `PiiRedactor` (CPF, CNPJ, e-mail, telefone), aviso na tela e documentação corrigida | `PiiRedactorTest` |

**Lição.** Regras de controle (quem pode aprovar, quando um processo está aberto) precisam ser pensadas pelos papéis reais das pessoas, não só pelo caminho feliz. E a documentação de privacidade deve prometer exatamente o que o código garante.
