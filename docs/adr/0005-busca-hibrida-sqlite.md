# 0005 — Busca híbrida em SQLite + numpy, sem banco vetorial

**Status:** aceita

## Contexto
O acervo são normas federais: termos exatos importam ("Libras", "10.048", "atendimento prioritário"), mas o diagnóstico usa outras palavras ("pessoas surdas" × "deficiência auditiva"). O volume é pequeno: 6 normas, 312 trechos.

## Decisão
- **BM25** (implementado no próprio serviço, com normalização para português) **+ embeddings locais** (`paraphrase-multilingual-MiniLM-L12-v2`, embutido na imagem Docker), combinados por **Reciprocal Rank Fusion**.
- Persistência em **SQLite**; busca exata em memória com numpy.
- Se o modelo não carregar, o serviço segue em BM25 e o `/health` informa `degraded`.

## Alternativas
- **pgvector / Qdrant:** necessários com centenas de milhares de trechos; nesta escala, seriam mais um serviço para operar sem ganho mensurável.
- **Só embeddings:** perde em siglas e números de lei.
- **Embeddings por API:** custo por documento e dependência externa na ingestão.

## Consequências
- (+) Custo zero de busca; funciona sem internet depois do build.
- (+) Resultado medido: hit@1 = 0,952 em 21 casos anotados (`app/evaluation/cases.json`); a primeira avaliação, com 8 casos, deu 0,875.
- (−) Índice em memória por processo: escala até dezenas de milhares de trechos. A classe `Retriever` isola a troca por um banco vetorial.
- (−) Ingestão pela CLI e servidor são processos diferentes; o índice é recarregado quando a assinatura do banco muda (bug real, ver estudos de caso).
