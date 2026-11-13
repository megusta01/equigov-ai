# Acervo de referência

Documentos públicos usados pela busca (RAG). Textos de lei não são protegidos por direito autoral (Lei nº 9.610/1998, art. 8º, IV).

| Arquivo | Documento |
|---|---|
| `l13146.htm` | Lei nº 13.146/2015 — Lei Brasileira de Inclusão |
| `l10436.htm` | Lei nº 10.436/2002 — Libras |
| `d5626.htm` | Decreto nº 5.626/2005 — Regulamenta a Lei de Libras |
| `l10048.htm` | Lei nº 10.048/2000 — Atendimento prioritário |
| `l13460.htm` | Lei nº 13.460/2017 — Direitos do usuário dos serviços públicos |
| `l12288.htm` | Lei nº 12.288/2010 — Estatuto da Igualdade Racial |

Os endereços estão em `sources.json`. Para baixar, abra cada link no navegador, use **Salvar como → Página da Web, somente HTML** e grave aqui com o nome da tabela.

O extrator lida com a codificação windows-1252 usada pelo Planalto e **ignora texto riscado** (dispositivos revogados).

Ingestão:

```bash
docker compose exec ai-service python -m app.cli ingest /corpus
docker compose exec ai-service python -m app.cli eval
```

Outros formatos aceitos: PDF (com texto, não escaneado), TXT e Markdown. Para PDFs e outros documentos, acrescente título e URL em `sources.json`.
