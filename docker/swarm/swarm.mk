# Single-node Swarm operations. Included at the end of the root Makefile.
# Uses `docker` directly (the root Makefile's COMPOSE_FILE only affects `docker compose`,
# so the dev targets are untouched). Run these on the Swarm server.

SWARM_STACK ?= vitalease
SWARM_DIR := docker/swarm
SWARM_ENV := $(SWARM_DIR)/swarm.env
SWARM_STACK_FILES := -c $(SWARM_DIR)/stack.yml
SERVICE_NAME ?= app
JOB_TIMEOUT ?= 7200

.PHONY: swarm-init swarm-secrets swarm-build swarm-deploy swarm-ps swarm-scale swarm-logs swarm-rm \
	swarm-migrate swarm-seed-demo swarm-reindex swarm-health swarm-sql

# Loads docker/swarm/swarm.env into the recipe shell and resolves IMAGE_TAG
# (explicit `make IMAGE_TAG=...` wins, then the env file, then the git short sha).
define swarm_load_env
set -eu; \
cli_tag="$${IMAGE_TAG:-}"; \
if [ ! -f $(SWARM_ENV) ]; then echo "Missing $(SWARM_ENV): copy $(SWARM_ENV).example and adjust it" >&2; exit 1; fi; \
set -a; . ./$(SWARM_ENV); set +a; \
IMAGE_TAG="$${cli_tag:-$${IMAGE_TAG:-$$(git rev-parse --short HEAD)}}"; export IMAGE_TAG
endef

# Container id of the first local task of a stack service (single node: always local).
swarm_cid = $$(docker ps -q --filter label=com.docker.swarm.service.name=$(SWARM_STACK)_$(1) | head -n 1)

swarm-init: ## Swarm: initialise a single-node swarm (no-op when already active; ADVERTISE_ADDR=<ip> if needed)
	@if [ "$$(docker info --format '{{.Swarm.LocalNodeState}}')" = "active" ]; then \
		echo "Swarm already active"; \
	else \
		docker swarm init $(if $(ADVERTISE_ADDR),--advertise-addr $(ADVERTISE_ADDR)); \
	fi

swarm-secrets: ## Swarm: generate missing secret files under docker/swarm/secrets and create the Docker secrets
	@set -eu; umask 077; dir=$(SWARM_DIR)/secrets; mkdir -p "$$dir"; \
	for name in app_key db_password redis_password meili_master_key; do \
		if [ ! -f "$$dir/$$name" ]; then \
			if [ "$$name" = app_key ]; then \
				printf 'base64:%s' "$$(head -c 32 /dev/urandom | base64)" > "$$dir/$$name"; \
			else \
				head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$$dir/$$name"; \
			fi; \
			echo "generated $$dir/$$name"; \
		fi; \
		if docker secret inspect "$(SWARM_STACK)_$$name" >/dev/null 2>&1; then \
			echo "secret $(SWARM_STACK)_$$name already exists"; \
		else \
			docker secret create "$(SWARM_STACK)_$$name" "$$dir/$$name" >/dev/null && echo "created secret $(SWARM_STACK)_$$name"; \
		fi; \
	done

swarm-build: ## Swarm: build the production images (vitalease/app and vitalease/nginx, tag IMAGE_TAG or git short sha)
	@$(swarm_load_env); \
	docker build --target app -t "vitalease/app:$$IMAGE_TAG" -f docker/production/Dockerfile .; \
	docker build --target nginx -t "vitalease/nginx:$$IMAGE_TAG" -f docker/production/Dockerfile .

swarm-deploy: ## Swarm: deploy or update the stack from the locally built images
	@$(swarm_load_env); \
	docker stack deploy --resolve-image never $(SWARM_STACK_FILES) $(SWARM_STACK)

swarm-ps: ## Swarm: show services and tasks of the stack
	@docker stack services $(SWARM_STACK)
	@docker stack ps --no-trunc $(SWARM_STACK)

swarm-scale: ## Swarm: scale a service (make swarm-scale SERVICE_NAME=app REPLICAS=3)
	@test -n "$(REPLICAS)" || { echo "Usage: make swarm-scale SERVICE_NAME=app REPLICAS=3" >&2; exit 1; }
	@docker service scale $(SWARM_STACK)_$(SERVICE_NAME)=$(REPLICAS)

swarm-logs: ## Swarm: follow service logs (SERVICE_NAME=app by default)
	@docker service logs --tail 200 -f $(SWARM_STACK)_$(SERVICE_NAME)

swarm-rm: ## Swarm: remove the stack (named volumes and secrets are kept)
	@docker stack rm $(SWARM_STACK)

swarm-migrate: ## Swarm: run migrations and sync search index settings as a one-off replicated job
	@STACK=$(SWARM_STACK) JOB_TIMEOUT=$(JOB_TIMEOUT) sh $(SWARM_DIR)/bin/run-job.sh migrate \
		sh -c 'php artisan migrate --force && php artisan scout:sync-index-settings'

swarm-seed-demo: ## Swarm: seed demo data as a one-off job (SEED_ARGS="--users=100 --patients=10000 --consultations=100000")
	@STACK=$(SWARM_STACK) JOB_TIMEOUT=$(JOB_TIMEOUT) sh $(SWARM_DIR)/bin/run-job.sh seed \
		php -d memory_limit=1G artisan demo:seed --force $(SEED_ARGS)

swarm-reindex: ## Swarm: re-import patients and medications into Meilisearch (idempotent, queued when SCOUT_QUEUE=true)
	@STACK=$(SWARM_STACK) JOB_TIMEOUT=$(JOB_TIMEOUT) sh $(SWARM_DIR)/bin/run-job.sh reindex-patients \
		php artisan scout:import 'App\Models\Patient'
	@STACK=$(SWARM_STACK) JOB_TIMEOUT=$(JOB_TIMEOUT) sh $(SWARM_DIR)/bin/run-job.sh reindex-medications \
		php artisan scout:import 'App\Models\Medication'

swarm-health: ## Swarm: print the internal /health report (served only on nginx's unpublished :8081 listener)
	@docker exec $(call swarm_cid,nginx) wget -qO- http://127.0.0.1:8081/health \
		|| echo "health endpoint unreachable or answered non-200"

swarm-sql: ## Swarm: open psql in the postgres task (local socket, no password needed)
	@$(swarm_load_env); \
	docker exec -it $(call swarm_cid,postgres) psql -U "$$DB_USERNAME" -d "$$DB_DATABASE"
