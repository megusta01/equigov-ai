SHELL := /bin/bash
DC := docker compose
PLUGIN := wordpress/plugins/equigov-core

ifneq (,$(wildcard .env))
include .env
endif

.PHONY: help up down install logs shell wp test smoke ai-test ai-lint ai-ingest ai-eval lint reset

help: ## Lista os comandos
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

.env:
	cp .env.example .env
	@echo "Criei .env a partir do exemplo. Revise as senhas."

up: .env ## Sobe os containers
	$(DC) up -d --build

install: up ## Instala WP, ativa o plugin e cria dados de demo
	$(DC) exec \
	  -e WP_URL="$(WP_URL)" -e WP_ADMIN_USER="$(WP_ADMIN_USER)" \
	  -e WP_ADMIN_PASSWORD="$(WP_ADMIN_PASSWORD)" -e WP_ADMIN_EMAIL="$(WP_ADMIN_EMAIL)" \
	  wordpress bash /scripts/bootstrap.sh

down: ## Para os containers
	$(DC) down

logs: ## Logs de todos os serviços
	$(DC) logs -f --tail=100

shell: ## Shell no container do WordPress
	$(DC) exec wordpress bash

wp: ## Roda WP-CLI. Ex: make wp ARGS="equigov seed"
	$(DC) exec wordpress wp --allow-root $(ARGS)

test: ## Testes unitários do plugin
	cd $(PLUGIN) && composer install --quiet && vendor/bin/phpunit

smoke: ## Integração: API + fluxo do plano com IA (precisa do install antes)
	$(DC) exec wordpress wp --allow-root eval-file wp-content/plugins/equigov-core/tests/smoke/api-smoke.php
	$(DC) exec wordpress wp --allow-root eval-file wp-content/plugins/equigov-core/tests/smoke/plan-smoke.php

ai-test: ## Testes do serviço de IA
	$(DC) run --rm --no-deps ai-service python -m pytest

ai-lint: ## Lint do serviço de IA
	$(DC) run --rm --no-deps ai-service ruff check app tests

ai-ingest: ## Ingere ai-service/corpus
	$(DC) exec ai-service python -m app.cli ingest /corpus

ai-eval: ## Avaliação da busca
	$(DC) exec ai-service python -m app.cli eval

lint: ## Verifica sintaxe PHP
	find $(PLUGIN)/src $(PLUGIN)/equigov-core.php -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null && echo "PHP ok"

reset: ## Apaga TUDO (inclusive o banco) e recomeça
	$(DC) down -v
