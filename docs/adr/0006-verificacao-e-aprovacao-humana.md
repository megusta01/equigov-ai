# 0006 — Verificação de citações e aprovação humana com separação de funções

**Status:** aceita

## Contexto
LLMs podem inventar fontes, citar documentos sem relação com a ação ou generalizar uma norma além do seu escopo. Num órgão público, uma recomendação sem base legal pode virar política.

## Decisão
Três camadas, nesta ordem:
1. **Verificador automático** (no serviço de IA): remove citações a trechos que não foram enviados; rejeita ação sem achado válido ou sem fonte válida; marca como `weak` a ação com pouca sobreposição de conteúdo com o trecho citado.
2. **Revisão humana item a item:** aceitar, editar ou rejeitar, vendo o **trecho original ao lado da justificativa**. O texto original da IA nunca é sobrescrito. Aceitar sugestão `weak` exige justificativa escrita.
3. **Aprovação por outra pessoa:** quem concluiu a revisão **ou decidiu qualquer item** não pode aprovar (`eg_segregation`). Um plano revisado e ainda não aprovado continua aberto: não se gera outro para o mesmo diagnóstico.

Tudo vai para a auditoria: modelo, versão do prompt, métricas de verificação, tokens e cada decisão.

## Consequências
- (+) Nenhuma sugestão vira plano sem duas pessoas diferentes.
- (+) Métrica objetiva de qualidade da IA por plano (`citation_validity`, fundamentadas/fracas/rejeitadas).
- (−) O verificador mede sobreposição de termos, não implicação lógica. Na avaliação manual, uma ação generalizou para "serviços públicos" uma norma sobre saúde e passou como `grounded`. Isso motivou o prompt `plan-v2` e reforça que a revisão humana é obrigatória, e não opcional.
