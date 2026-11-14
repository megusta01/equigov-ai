# EquiGov AI

[![CI](https://github.com/megusta01/equigov-ai/actions/workflows/ci.yml/badge.svg)](https://github.com/megusta01/equigov-ai/actions/workflows/ci.yml)
[![Licença: GPL v2+](https://img.shields.io/badge/licen%C3%A7a-GPL--2.0--or--later-blue.svg)](LICENSE)

Plataforma para órgãos públicos **diagnosticarem riscos de desigualdade racial e barreiras de acesso** nos seus serviços e transformarem o diagnóstico em **planos de ação** com apoio de IA, em que cada sugestão é **rastreável até o trecho da norma que a fundamenta** e só vale depois de **revisão e aprovação por pessoas diferentes**.

| Diagnóstico explicável | Revisão com a fonte ao lado | Plano aprovado e rastreável |
|---|---|---|
| ![Relatório do diagnóstico](docs/img/relatorio.png) | ![Revisão das sugestões da IA](docs/img/revisao.png) | ![Plano aprovado](docs/img/aprovado.png) |

## O problema

Um serviço público pode ser formalmente aberto a todos e, na prática, reproduzir desigualdades. Sem o quesito raça/cor nos registros, ninguém percebe se pessoas negras esperam mais ou têm mais pedidos negados. Sem protocolo, casos de discriminação no atendimento não são apurados. Canal só digital, falta de atendimento em Libras e linguagem inacessível excluem outros públicos da mesma forma silenciosa.

Os gestores raramente têm um instrumento para medir esses riscos. Quando têm, falta tempo para ligar cada problema à obrigação legal correspondente e a uma medida concreta. Usar IA para isso cria um risco novo: recomendações que soam plausíveis, mas citam normas inexistentes ou fora do contexto.

## A proposta

O EquiGov AI parte de três princípios:

1. **Diagnóstico auditável, sem IA.** A nota de risco vem de uma regra fixa e versionada. Cada achado mostra a resposta, a regra aplicada, a referência legal e quantos pontos pesou; as contribuições somam a nota. O resultado é reprodutível e pode ser contestado.
2. **A IA sugere, nunca decide.** Para cada achado, o sistema busca trechos de normas reais e pede ações ao modelo de linguagem. Um verificador remove citações a trechos que não foram enviados e rejeita sugestões sem fonte ou sem achado correspondente.
3. **Controle humano com separação de funções.** Um revisor decide cada sugestão (aceitar, editar ou rejeitar) lendo o trecho original da norma ao lado. O texto da IA nunca é sobrescrito. Quem participou da revisão não pode aprovar: a aprovação final é de outra pessoa.

```
Gestor responde o questionário ─▶ pontuação determinística e explicável
        │
        ▼
Gestor pede sugestões ─▶ busca nas normas por achado ─▶ LLM propõe ─▶ verificador confere cada citação
        │
        ▼
Revisor decide cada sugestão (aceitar · editar · rejeitar), com o trecho original da norma ao lado
        │
        ▼
Outro gestor aprova ou rejeita ─▶ plano final com a origem de cada ação ─▶ trilha de auditoria
```

## O que foi construído

**Diagnóstico**
- Dois questionários: **maturidade institucional em igualdade racial** (dados raciais, governança, enfrentamento do racismo institucional, formação, participação) e **acesso inclusivo a serviços públicos**. Novos questionários são arquivos JSON versionados, sem código.
- Perguntas condicionais, validação completa no servidor e descarte de respostas que ficaram ocultas.
- Cada diagnóstico guarda uma cópia da versão do questionário: editar o questionário depois não altera diagnósticos já abertos.

**Plano de ação com IA**
- Serviço em Python que indexa normas (HTML, PDF, texto), busca trechos por **BM25 + embeddings locais** combinados por Reciprocal Rank Fusion e gera sugestões por um LLM acessado em formato compatível com a OpenAI.
- Verificador de citações, prompt versionado, cache de respostas, novas tentativas só para erros transitórios e tratamento dos limites do provedor.
- Filtro que remove CPF, CNPJ, e-mail e telefone do texto livre antes de enviá-lo ao provedor externo.

**Fluxo de revisão e aprovação**
- Máquina de estados com decisão por item, justificativa obrigatória para rejeitar ou aceitar sugestão de suporte fraco, separação de funções e trava contra gerações simultâneas.
- Trilha de auditoria: modelo, versão do prompt, métricas de verificação e tokens de cada geração; cada decisão; quem revisou e quem aprovou.

**Segurança e isolamento**
- Várias instituições na mesma instalação: cada uma só vê os próprios registros (404 para registros de outra, sem revelar que existem).
- Papéis e capacidades próprios, editor nativo do WordPress desligado para diagnósticos e planos, consultas SQL parametrizadas, interface sem `innerHTML` para conteúdo externo.

**Interface, infraestrutura e qualidade**
- Tela no painel do WordPress em JavaScript puro, com rótulos, foco visível e mensagens anunciadas a leitores de tela.
- Ambiente reproduzível em Docker Compose (Nginx, PHP-FPM, MariaDB, serviço de IA), com healthchecks e scripts de operação.
- Testes em quatro camadas e pipeline de CI a cada push.

## Resultados

| | |
|---|---|
| **Testes** | 70 PHPUnit · 60 pytest · 66 verificações de integração contra WordPress, MariaDB e IA reais · E2E em navegador (Playwright) |
| **Busca nas normas** | hit@1 = 0,952 em 21 casos anotados (20 acertos em primeiro lugar) |
| **Geração** | 100% das citações válidas nos planos reais avaliados; cerca de 3 a 5 mil tokens por plano |
| **CI** | lint, testes unitários, integração, E2E, varredura de segredos, auditoria de dependências e build das imagens |

A avaliação da busca e a revisão humana dos planos gerados, inclusive o problema que levou à segunda versão do prompt, estão descritas no [README do serviço de IA](ai-service/README.md#avaliação).

## Stack e ferramentas

| Camada | Tecnologia |
|---|---|
| Aplicação | WordPress + plugin próprio em PHP 8.3 (PSR-4, sem dependências em produção) |
| Banco de dados | MariaDB 11.4: tabelas próprias, chaves únicas, transações, schema versionado |
| API | WordPress REST API (`equigov/v1`), validação por JSON Schema |
| Interface | JavaScript puro e CSS no painel do WordPress |
| IA | Python 3.12, FastAPI, Pydantic, fastembed (embeddings locais), SQLite + NumPy, httpx; LLM via API compatível com OpenAI (padrão: Groq) |
| Infraestrutura | Docker Compose, Nginx, PHP-FPM, WP-CLI |
| Qualidade | PHPUnit, pytest, Playwright, ruff, actionlint |
| Segurança | gitleaks, composer audit, pip-audit |
| CI | GitHub Actions |

## Rodando localmente

Requisito: Docker (Docker Desktop no Windows ou macOS). Para a geração real, uma chave gratuita do Groq em console.groq.com; sem chave, use `LLM_PROVIDER=fake` no `.env`.

```powershell
# Windows (PowerShell)                      # Linux/macOS: make <tarefa>
.\tasks.ps1 install     # cria .env, sobe os containers, instala o WordPress, ativa o plugin e cria dados de demonstração
.\tasks.ps1 ai-ingest   # indexa as normas de ai-service/corpus
.\tasks.ps1 test        # PHPUnit
.\tasks.ps1 ai-test     # pytest
.\tasks.ps1 smoke       # integração de ponta a ponta
```

Acesse `http://localhost:8080/wp-admin/` → menu **EquiGov**. Usuários de demonstração (senha `demo-equigov-2026`): `gestora.a`, `revisor.a`, `leitor.a` (Instituição A) e `gestor.b` (Instituição B).

## Documentação

| Documento | Conteúdo |
|---|---|
| [Arquitetura](docs/arquitetura.md) | Componentes, modelo de dados, fluxos e estados (diagramas Mermaid) |
| [Decisões (ADRs)](docs/adr/README.md) | 8 decisões com alternativas e consequências |
| [API REST](docs/api.md) | Autenticação, autorização, erros, rotas, regra de pontuação |
| [Serviço de IA](ai-service/README.md) | Busca, geração, verificador de citações, avaliação |
| [Segurança e LGPD](docs/seguranca.md) | Ameaças, controles e os testes que os cobrem |
| [Runbook](docs/runbook.md) | Operação, saúde, logs, problemas conhecidos, backup |
| [Estudos de caso](docs/estudos-de-caso.md) | Falhas reais encontradas: causa, correção e verificação |

## Estrutura

```
├── wordpress/plugins/equigov-core/   plugin: domínio, API, interface, questionários, testes (unit, smoke, e2e)
├── ai-service/                       serviço de IA: aplicação, testes, acervo de normas (corpus/)
├── infrastructure/                   Nginx, PHP-FPM, scripts de bootstrap
├── docs/                             arquitetura, ADRs, API, segurança, runbook, estudos de caso
├── .github/workflows/ci.yml          pipeline
├── docker-compose.yml
└── tasks.ps1 · Makefile              atalhos de operação
```

## Limitações e próximos passos

- **Geração síncrona**, limitada a 55 s. Próximo passo: fila assíncrona com notificação.
- **O verificador mede sobreposição de termos** e não detecta generalização de escopo (uma norma federal apresentada como obrigação municipal, por exemplo). A revisão humana cobre essa lacuna; o próximo passo é uma checagem de implicação por um segundo modelo.
- **O filtro de dados pessoais não detecta nomes**; a interface orienta a não escrevê-los.
- **Acervo inicial** de 6 normas federais. Ampliar com normas estaduais, municipais e guias técnicos.
- **Painéis de acompanhamento** (evolução do risco, execução das ações) ficaram fora desta versão.
- Produção exigiria HTTPS, limite de requisições na borda e um plano pago do provedor de LLM.

## Licença

[GPL-2.0-or-later](LICENSE), a mesma licença exigida de plugins WordPress, aplicada ao repositório inteiro.

Desenvolvido por Gustavo Figueredo.
