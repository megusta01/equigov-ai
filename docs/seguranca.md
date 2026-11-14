# Segurança e proteção de dados

## Ameaças e controles

| Ameaça | Controle | Onde | Teste |
|---|---|---|---|
| Acesso a dados de outra instituição | Isolamento em `map_meta_cap`; listagens filtradas na consulta; 404 para não revelar existência | `InstitutionScope`, `InstitutionPolicy` | `InstitutionPolicyTest`, smokes |
| Escalada de privilégio | Capacidades próprias `eg_*`; CPTs nunca caem em capacidades nativas; editor nativo desligado | `Roles`, `Registrar` | `RolesTest` |
| Uma pessoa controlar todo o processo | Quem concluiu a revisão **ou decidiu qualquer item** não aprova; sugestão fraca exige justificativa | `PlanWorkflow` | `PlanWorkflowTest`, `plan-smoke` |
| Planos duplicados por requisições simultâneas | Trava por diagnóstico no MariaDB (`GET_LOCK`); plano revisado ainda conta como aberto | `ActionPlanService` | `plan-smoke` |
| Dado pessoal no texto livre enviado à IA | Filtro de CPF, CNPJ, e-mail e telefone; aviso na tela para não escrever nomes | `PiiRedactor`, `admin.js` | `PiiRedactorTest` |
| Injeção de SQL | `$wpdb->prepare()` em toda consulta com entrada externa | `Repositories` | revisão de código |
| XSS (inclusive conteúdo gerado por IA) | Interface insere texto só por `textContent`; nunca `innerHTML` | `assets/admin.js` | E2E |
| CSRF | Cookie + nonce `wp_rest` (`X-WP-Nonce`) | `AdminPage` | — |
| Entrada inválida | JSON Schema nos `args` da REST; sanitização em todo metadado; Pydantic com limites no serviço | controllers, `schemas.py` | smokes, `test_api.py` |
| Prompt injection via documentos | Trechos marcados como dados no prompt; verificador de citações; aprovação humana | `prompts.py`, `verifier.py` | `test_planner.py` |
| Citação inventada pela IA | Citação a trecho não enviado é removida; ação sem fonte é rejeitada | `verifier.py` | `test_verifier.py` |
| Chamadas não autorizadas ao serviço de IA | Token de serviço comparado em tempo constante; porta só em `127.0.0.1` | `main.py`, compose | `test_api.py` |
| Upload malicioso | Lista de extensões, limite de tamanho, URL só `http(s)` | `main.py` | `test_api.py` |
| Vazamento de segredos | `.env` fora do Git; gitleaks na CI; `DISALLOW_FILE_EDIT` | `.gitignore`, CI | job `security` |
| Dependências vulneráveis | `composer audit` e `pip-audit` na CI | CI | job `security` |
| Exposição de arquivos | Nginx bloqueia `.env`, `.git`, `xmlrpc.php` e PHP em `uploads/`; cabeçalhos de segurança | `default.conf` | — |
| Indisponibilidade do provedor de IA | Timeout, retries limitados, erros claros; IA fora do caminho crítico | `llm.py`, `AiClient` | `test_llm_client.py` |

## LGPD

- **Minimização:** o serviço de IA e o provedor externo recebem os achados do diagnóstico (perguntas, respostas, regras, referências) e, se o gestor preencher, um contexto livre da instituição. O sistema não envia dados de usuários nem a identificação da instituição. O contexto livre passa por um filtro que remove CPF, CNPJ, e-mail e telefone; **nomes de pessoas não são detectáveis automaticamente**, por isso a tela orienta a não escrevê-los.
- **IP em auditoria:** gravado como HMAC-SHA256 com salt do WordPress. Permite correlacionar eventos sem guardar o IP.
- **Dados de pessoas atendidas:** o sistema avalia **processos institucionais**, não indivíduos. O questionário pergunta *se* a instituição coleta dados desagregados, nunca os próprios dados.
- **Provedor de LLM:** escolhido entre os que declaram não usar dados de API para treino. Trocar de provedor é configuração (ADR 0007).
- **Retenção:** desativar o plugin não apaga dados; a remoção é decisão explícita do administrador.

## Limitações conhecidas

- Não há limitação de taxa (*rate limiting*) na API do WordPress; em produção, aplicar no Nginx ou num WAF.
- HTTPS é responsabilidade do proxy de borda; o compose local usa HTTP.
- A trilha de auditoria é append-only por convenção da aplicação, não por permissão do banco.
