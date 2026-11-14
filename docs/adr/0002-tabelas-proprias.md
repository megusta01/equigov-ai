# 0002 — Tabelas próprias para respostas e auditoria

**Status:** aceita

## Contexto
Cada diagnóstico tem dezenas de respostas, e cada ação gera eventos de auditoria. No modelo padrão do WordPress, isso viraria linhas em `wp_postmeta`, uma tabela chave-valor genérica, com índice só em `meta_key` e valores `LONGTEXT`.

## Decisão
- `wp_eg_responses`: uma linha por resposta, `UNIQUE (diagnosis_id, question_key)` e índice por instituição. Gravação em lote com `INSERT … ON DUPLICATE KEY UPDATE` dentro de **transação**.
- `wp_eg_audit_log`: append-only, índices `(institution_id, created_at)` e `(object_type, object_id)`, contexto em JSON, IP como HMAC-SHA256 com salt.
- Schema versionado (`equigov_db_version`) aplicado por `dbDelta` no `Installer`.

Conteúdo editorial (questionários, diagnósticos, planos) continua em CPTs, que ganham revisões, taxonomias e permissões.

## Consequências
- (+) Integridade (uma resposta por pergunta garantida pelo banco), consultas indexadas, escrita atômica.
- (+) Auditoria consultável por instituição e período sem varrer postmeta.
- (−) Migrações manuais: mudar coluna exige subir `DB_VERSION`.
- (−) Exportação e backup precisam incluir as tabelas `wp_eg_*`, além das nativas.
