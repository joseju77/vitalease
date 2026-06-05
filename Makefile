verify_env := $(shell [ ! -f .env ] && cp .env.example .env)
include .env

SSH_PUBLIC_KEY_PATH ?= $(HOME)/.ssh/id_ed25519.pub
verify_ssh_key := $(shell mkdir -p docker/ssh && [ ! -f docker/ssh/authorized_keys ] && cp $(SSH_PUBLIC_KEY_PATH) docker/ssh/authorized_keys)

export COMPOSE_PROJECT_NAME := $(shell echo $(APP_NAME) | tr '[:upper:]' '[:lower:]' | sed 's/ /-/g')
export COMPOSE_FILE := docker-compose.dev.yml

.PHONY: help setup build rebuild start stop down up restart recreate laravel-shell laravel-shell-root postgres-shell nginx-shell redis-shell lint lint-fix lint-staged test test-db test-frontend test-all

help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-10s\033[0m %s\n", $$1, $$2}'

setup: up ## First-time setup: install dependencies, generate app key, run migrations
	@docker compose exec -T -u www-data laravel composer install
	@docker compose exec -T -u www-data laravel npm install
	@docker compose exec -T -u www-data laravel sh -c 'grep -q "^APP_KEY=base64" .env || php artisan key:generate'
	@docker compose exec -T -u www-data laravel php artisan migrate

# --- Build, Start, Stop, Restart, Recreate ---
build: ## Build docker containers
	@docker compose build $(if $(NO_CACHE),--no-cache) $(SERVICE)

rebuild: down build up ## Rebuild docker containers (stop, build, start)

start: ## Start docker containers
	@docker compose start $(SERVICE)

stop: ## Stop docker containers
	@docker compose stop $(SERVICE)

down: ## Stop and remove docker containers
	@docker compose down $(SERVICE)

up: ## Create and start docker containers
	@docker compose up -d $(SERVICE)

restart: ## Restart docker containers
	@docker compose restart $(SERVICE)

recreate: ## Recreate docker containers
	@docker compose up -d --force-recreate $(SERVICE)

# --- Shell ---
laravel-shell: ## Shell into the laravel fpm container
	@docker compose exec -u www-data laravel bash

laravel-shell-root: ## Shell into the laravel fpm container as root
	@docker compose exec -u root laravel bash

postgres-shell: ## Shell into the dev postgres container
	@docker compose exec postgres bash

nginx-shell: ## Shell into the nginx container
	@docker compose exec nginx sh

redis-shell: ## Shell into the redis container
	@docker compose exec redis sh

# --- Quality ---
lint: ## Run all linters in check-only mode (ESLint, Prettier, Pint)
	@docker compose exec -T -u www-data laravel npm run lint
	@docker compose exec -T -u www-data laravel npm run format:check
	@docker compose exec -T -u www-data laravel vendor/bin/pint --test

lint-fix: ## Auto-fix lint/formatting issues (ESLint, Prettier, Pint)
	@docker compose exec -T -u www-data laravel npm run lint:fix
	@docker compose exec -T -u www-data laravel npm run format
	@docker compose exec -T -u www-data laravel vendor/bin/pint

lint-staged: ## Run lint-staged against the currently staged files (used by the pre-commit hook)
	@docker compose exec -T -u www-data laravel npx lint-staged

test-db: ## Ensure the dedicated Postgres test database exists
	@docker compose exec -T -e PGPASSWORD=$(DB_PASSWORD) postgres createdb -U $(DB_USERNAME) vitalease_testing 2>/dev/null || true

test: test-db ## Run the backend test suite
	@docker compose exec -T -u www-data laravel php artisan test

test-frontend: ## Run the frontend test suite
	@docker compose exec -T -u www-data laravel npm run test

test-all: test test-frontend ## Run both backend and frontend test suites