# Business Tool — common tasks. Run `make help` for the list.
.DEFAULT_GOAL := help
COMPOSE := docker compose
PNPM := pnpm --dir frontend
PHP := $(COMPOSE) exec php
CONSOLE := $(PHP) bin/console

## —— Environment ————————————————————————————————————————————————
up: ## Start the API (FrankenPHP) and PostgreSQL
	$(COMPOSE) up -d --wait

down: ## Stop containers
	$(COMPOSE) down

build: ## Rebuild the API image
	$(COMPOSE) build --pull

logs: ## Follow API logs
	$(COMPOSE) logs -f php

sh: ## Shell into the API container
	$(PHP) bash

install: ## Install PHP and JS dependencies
	$(PHP) composer install
	$(PNPM) install

setup: up install db-reset types ## First run: start, install, seed demo data, generate API types

## —— Backend ————————————————————————————————————————————————————
console: ## Run a Symfony console command: make console c="debug:router"
	$(CONSOLE) $(c)

migration: ## Generate a migration from entity changes
	$(CONSOLE) make:migration --no-interaction

migrate: ## Run pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

db-reset: ## Recreate the dev database and load demo fixtures
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create
	$(CONSOLE) doctrine:migrations:migrate --no-interaction
	$(CONSOLE) doctrine:fixtures:load --no-interaction

db-validate: ## Check that mapping and migrations match the database
	$(CONSOLE) doctrine:schema:validate

test: ## Run PHPUnit
	$(PHP) bin/phpunit

phpstan: ## Run static analysis
	$(CONSOLE) cache:warmup --quiet
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

deptrac: ## Check layer dependencies (Domain/Application/Infrastructure/Ui)
	$(PHP) vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress

## —— Frontend ———————————————————————————————————————————————————
admin: ## Start the admin panel dev server (http://localhost:5173)
	$(PNPM) dev:admin

timeclock: ## Start the timeclock PWA dev server (http://localhost:5174)
	$(PNPM) dev:timeclock

types: ## Regenerate the TypeScript API client from OpenAPI
	$(PNPM) api:types

## —— Quality ————————————————————————————————————————————————————
qa: db-validate deptrac phpstan test ## Run every check
	$(PNPM) typecheck
	$(PNPM) lint
	$(PNPM) build

help:
	@grep -hE '(^[a-zA-Z_-]+:.*?## .*$$)|(^## )' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-14s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m## /\n[33m/'

.PHONY: up down build logs sh install setup console migration migrate db-reset db-validate deptrac test phpstan admin timeclock types qa help
