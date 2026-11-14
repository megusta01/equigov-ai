# 0004 — Serviço de IA separado, em Python

**Status:** aceita

## Contexto
A geração de sugestões exige extração de PDF/HTML, embeddings, busca e cliente de LLM. Essas funções são lentas, dependem de serviços externos e têm um ecossistema de bibliotecas muito mais maduro em Python.

## Decisão
Um serviço FastAPI (`ai-service`) na rede interna do Docker, chamado só pelo WordPress, autenticado por token de serviço (`X-Service-Token`, comparado em tempo constante). Porta exposta apenas em `127.0.0.1` para a documentação interativa.

## Consequências
- (+) Isolamento de falhas: sem IA, o restante do sistema funciona; erros chegam ao gestor com código e mensagem claros.
- (+) O serviço pode escalar, ser trocado ou atender outro sistema sem mexer no PHP.
- (+) Minimização de dados: o WordPress envia apenas os achados, sem nomes, e-mails ou identificação da instituição.
- (−) Mais um container e mais uma fronteira de segurança (token compartilhado, que precisa de rotação).
- (−) Chamada síncrona limitada a 55 s pelo PHP-FPM. Evolução prevista: fila assíncrona (Action Scheduler) com notificação.
