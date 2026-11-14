# 0001 — WordPress (plugin) como núcleo da aplicação

**Status:** aceita

## Contexto
O sistema precisa de usuários, papéis, conteúdo editorial (questionários, biblioteca), API REST e painel administrativo. Órgãos públicos brasileiros usam WordPress amplamente, e equipes de TI do setor público costumam ter quem o opere.

## Decisão
Implementar a aplicação como **plugin** WordPress (`equigov-core`), usando o que a plataforma oferece (usuários, capacidades, CPTs, REST API, WP-CLI) e mantendo as regras de negócio em classes PHP puras, independentes do WordPress.

## Alternativas
- **Laravel/Symfony:** mais controle, mas reimplementaria autenticação, painel e gestão de conteúdo, e seria menos familiar para quem vai operar.
- **Tema com lógica:** acopla regra de negócio à apresentação; trocar o tema quebraria o sistema.

## Consequências
- (+) Autenticação, sessões, nonces, Application Passwords e painel prontos.
- (+) Regras testáveis sem WordPress (PHPUnit puro) por causa da separação domínio/infraestrutura.
- (−) Peculiaridades da plataforma exigem cuidado: `update_post_meta` remove barras (JSON gravado com `wp_slash`), `mysql_to_rfc3339` omite fuso (classe `Support\Time`), `dbDelta` é sensível a formatação.
- (−) Os editores nativos de diagnóstico e plano precisaram ser desligados (`show_ui => false`) para que nenhuma alteração contorne o fluxo.
