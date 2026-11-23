# Business Tool — common tasks. Run `make help` for the list.
.DEFAULT_GOAL := help
COMPOSE := docker compose
PNPM := pnpm --dir frontend
PHP := $(COMPOSE) exec php
CONSOLE := $(PHP) bin/console

## —— Environment ————————————————————————————————————————————————
up: ## Start the API (FrankenPHP), queue workers, PostgreSQL and Mailpit
	$(COMPOSE) up -d --wait

down: ## Stop containers
	$(COMPOSE) down

build: ## Rebuild the API image
	$(COMPOSE) build --pull

logs: ## Follow API and worker logs
	$(COMPOSE) logs -f php worker jobs-worker

worker-restart: ## Restart the queue workers (they do not reload code or templates by themselves)
	$(COMPOSE) restart worker jobs-worker

mail: ## Open the Mailpit inbox with every e-mail sent in dev (http://localhost:8025)
	@url=http://localhost:$${MAILPIT_PORT:-8025}; echo "Mailpit: $$url"; \
	(open "$$url" || xdg-open "$$url") >/dev/null 2>&1 || true

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
	@# The queue workers hold connections (LISTEN) that block DROP DATABASE; start them again even if a step fails.
	$(COMPOSE) stop worker jobs-worker
	@trap '$(COMPOSE) start worker jobs-worker' EXIT; set -e; \
	$(CONSOLE) doctrine:database:drop --force --if-exists; \
	$(CONSOLE) doctrine:database:create; \
	$(CONSOLE) doctrine:migrations:migrate --no-interaction; \
	$(CONSOLE) doctrine:fixtures:load --no-interaction

db-validate: ## Check that mapping and migrations match the database
	$(CONSOLE) doctrine:schema:validate

test: ## Run PHPUnit
	$(PHP) bin/phpunit

phpstan: ## Run static analysis
	$(CONSOLE) cache:warmup --quiet
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

translations-check: ## Lint the API's translation catalogs and fail on an e-mail text missing in a language (ADR 0028 §6)
	$(PHP) sh -c 'set -e; bin/console lint:translations; for locale in en pl; do bin/console debug:translation $$locale --domain=email --only-missing; done'

deptrac: ## Check layer dependencies (Domain/Application/Infrastructure/Ui)
	$(PHP) vendor/bin/deptrac analyse --config-file=deptrac.yaml --no-progress

## —— Frontend ———————————————————————————————————————————————————
admin: ## Start the admin panel dev server (http://localhost:5173)
	$(PNPM) dev:admin

timeclock: ## Start the timeclock PWA dev server (http://localhost:5174)
	$(PNPM) dev:timeclock

types: ## Regenerate the TypeScript API client from OpenAPI
	$(PNPM) api:types

test-frontend: ## Run the frontend unit tests (vitest)
	$(PNPM) test

translations-frontend: ## Check every frontend catalog has every English key, plural form and placeholder (ADR 0028; part of test-frontend)
	$(PNPM) test:catalogs

## —— Production (adr/0035) ——————————————————————————————————————
PROD_IMAGE ?= business-tool-api:prod
prod-image: ## Build the production image, API + both SPAs (TIMECLOCK_URL=https://app.tooltipp.com)
	docker build --target prod --build-context frontend=frontend \
		--build-arg VITE_TIMECLOCK_URL=$(or $(TIMECLOCK_URL),http://app.localhost) -t $(PROD_IMAGE) api

prod-smoke: ## Smoke-test the production image without a database (after make prod-image)
	.github/scripts/smoke-prod-image.sh $(PROD_IMAGE)

deploy-check: ## Validate the deploy/ Compose files and the edge Caddyfile
	.github/scripts/check-deploy-config.sh

deploy: ## Deploy a stage from here, as CI does: make deploy STAGE=staging API_IMAGE=ghcr.io/... SECRETS_DIR=$$HOME/business-tool-secrets [DEPLOY_SSH_KEY_FILE=...]
	deploy/deploy.sh $(STAGE)

## —— Quality ————————————————————————————————————————————————————
adr-check: ## Check ADR numbering, statuses and index (adr/)
	.github/scripts/check-adr-hygiene.sh

guards: ## PR guards from CI: no new suppressions, no edited merged migrations (BASE_REF=origin/main)
	BASE_REF=$(or $(BASE_REF),origin/main) .github/scripts/check-suppressions.sh
	BASE_REF=$(or $(BASE_REF),origin/main) .github/scripts/check-merged-migrations.sh

qa: adr-check guards db-validate translations-check deptrac phpstan test ## Run every check
	$(PNPM) typecheck
	$(PNPM) lint
	$(PNPM) test
	$(PNPM) build

help:
	@grep -hE '(^[a-zA-Z_-]+:.*?## .*$$)|(^## )' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-14s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m## /\n[33m/'

.PHONY: up down build logs worker-restart mail sh install setup console migration migrate db-reset db-validate translations-check deptrac test phpstan admin timeclock types prod-image prod-smoke deploy-check deploy adr-check guards qa help
