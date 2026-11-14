# 0003 — Pontuação do diagnóstico determinística, sem IA

**Status:** aceita

## Contexto
O diagnóstico classifica o risco de desigualdade no acesso a um serviço público. Esse resultado pode orientar decisões e precisa ser contestável: o gestor deve conseguir saber de onde veio cada ponto.

## Decisão
Pontuação por fórmula fixa e versionada (`scoring-v1`):
`Σ(peso × risco) / Σ(peso) × 100` sobre as perguntas visíveis. O nível é dado por faixas, e qualquer resposta crítica eleva o nível para no mínimo "alto". Cada achado registra pergunta, resposta, regra em texto, referência legal e **contribuição em pontos**, e as contribuições somam a nota.

A IA entra **depois**, para sugerir medidas a partir dos achados, e nunca para classificar.

## Alternativas
- **LLM classificando o risco:** resultado não reprodutível (mesma entrada, notas diferentes), difícil de auditar e de contestar.

## Consequências
- (+) Reprodutível, explicável e testável à mão (`ScoringEngineTest` confere as contas).
- (+) `rule_version` no resultado: se a fórmula mudar, sabe-se com qual regra cada diagnóstico foi calculado.
- (−) Pesos e riscos são definidos por quem escreve o questionário; a qualidade do diagnóstico depende dessa curadoria.
