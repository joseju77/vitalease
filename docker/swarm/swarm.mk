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
	swarm-migrate swarm-seed-demo swarm-reindex swarm-health swarm-sql \
	load-run load-inspect swarm-sample swarm-sample-stop swarm-inject swarm-sql-check

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

# --- Load testing (k6) ---
# `load-*` targets need only Docker and run from the second (LAN) machine, e.g.
#   make -f docker/swarm/swarm.mk load-run SCRIPT=scenarios/login.js BASE_URL=http://<server>:80
# (-f avoids the root Makefile's dev-environment setup on a machine that only generates load).
# `swarm-sample*`, `swarm-inject` and `swarm-sql-check` run on the Swarm server.

# Pinned official image (digest at pin time: sha256:2dd4756ec6e14e62778916e1eb850108e4b499304c02df56760fa0bdb0fba0b8).
K6_IMAGE ?= grafana/k6:2.3.0
LOAD_DIR := tests/Load
RUN_ID ?= $(shell date -u +%Y%m%dT%H%M%SZ)
RESULTS_DIR ?= $(LOAD_DIR)/results/$(RUN_ID)
# `--security-opt label=disable` lets the container write the results mount on SELinux hosts
# without relabelling the host directory (it is a no-op elsewhere).
# Extra k6 flags, e.g. K6_ENV="-e VUS=50 -e DURATION=2m -e STOCK=200".
K6_ENV ?=

load-run: ## Load: run a k6 script from tests/Load (SCRIPT=scenarios/login.js BASE_URL=http://host [K6_ENV="-e VUS=50"] [RESULTS_DIR=...])
	@test -n "$(SCRIPT)" || { echo "Usage: make load-run SCRIPT=scenarios/login.js BASE_URL=http://<server> [K6_ENV=\"-e VUS=50 -e DURATION=2m\"]" >&2; exit 1; }
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required, e.g. BASE_URL=http://192.168.1.10" >&2; exit 1; }
	@test -f "$(LOAD_DIR)/$(SCRIPT)" || { echo "Unknown script $(LOAD_DIR)/$(SCRIPT)" >&2; exit 1; }
	@mkdir -p "$(RESULTS_DIR)"
	@echo "k6 $(SCRIPT) -> $(BASE_URL) (run id $(RUN_ID), results in $(RESULTS_DIR))"
	@rc_file="$(RESULTS_DIR)/.k6-exit-code"; \
	{ docker run --rm --user "$$(id -u):$$(id -g)" --security-opt label=disable \
		-v "$(CURDIR)/$(LOAD_DIR):/scripts:ro" -v "$(CURDIR)/$(RESULTS_DIR):/results" \
		$(K6_IMAGE) run -e BASE_URL="$(BASE_URL)" -e RUN_ID="$(RUN_ID)" $(K6_ENV) \
		/scripts/$(SCRIPT); echo $$? > "$$rc_file"; } 2>&1 | tee "$(RESULTS_DIR)/k6-stdout.txt"; \
	rc="$$(cat "$$rc_file")"; rm -f "$$rc_file"; \
	if [ "$$rc" -ne 0 ]; then echo "k6 exited with $$rc (99 = a threshold failed)" >&2; fi; exit "$$rc"

load-inspect: ## Load: validate every k6 script with `k6 inspect` (no network needed)
	@set -eu; for script in $$(cd $(LOAD_DIR) && find scenarios experiments -name '*.js' | sort); do \
		printf '%s: ' "$$script"; \
		docker run --rm -v "$(CURDIR)/$(LOAD_DIR):/scripts:ro" $(K6_IMAGE) inspect -e BASE_URL=http://localhost "/scripts/$$script" >/dev/null && echo ok; \
	done

swarm-sample: ## Swarm server: start the docker stats + health samplers (RESULTS_DIR=... INTERVAL=1 pause seconds)
	@set -eu; mkdir -p "$(RESULTS_DIR)"; \
	if [ -f "$(RESULTS_DIR)/.sampler.pids" ]; then echo "Samplers already started in $(RESULTS_DIR); run swarm-sample-stop first" >&2; exit 1; fi; \
	STACK=$(SWARM_STACK) nohup sh $(LOAD_DIR)/bin/docker-stats.sh "$(RESULTS_DIR)/docker-stats.csv" $(or $(INTERVAL),1) >/dev/null 2>&1 & stats_pid=$$!; \
	STACK=$(SWARM_STACK) nohup sh $(LOAD_DIR)/bin/health-poll.sh "$(RESULTS_DIR)/health.csv" 1 >/dev/null 2>&1 & health_pid=$$!; \
	echo "$$stats_pid $$health_pid" > "$(RESULTS_DIR)/.sampler.pids"; \
	echo "Sampling into $(RESULTS_DIR) (docker-stats.csv, health.csv); stop with: make swarm-sample-stop RESULTS_DIR=$(RESULTS_DIR)"

swarm-sample-stop: ## Swarm server: stop the samplers started by swarm-sample (same RESULTS_DIR)
	@set -eu; pids="$(RESULTS_DIR)/.sampler.pids"; \
	test -f "$$pids" || { echo "No samplers recorded in $(RESULTS_DIR)" >&2; exit 1; }; \
	for pid in $$(cat "$$pids"); do kill "$$pid" 2>/dev/null || true; done; \
	rm -f "$$pids"; echo "Samplers stopped; data in $(RESULTS_DIR)"

swarm-inject: ## Swarm server: inject one failure (INJECT=kill-app|meilisearch-0|meilisearch-1|redis-0|redis-1|postgres-0|postgres-1|restart-nginx RESULTS_DIR=...)
	@test -n "$(INJECT)" || { echo "Usage: make swarm-inject INJECT=kill-app RESULTS_DIR=<dir>" >&2; exit 1; }
	@mkdir -p "$(RESULTS_DIR)"
	@STACK=$(SWARM_STACK) sh $(LOAD_DIR)/bin/inject.sh "$(RESULTS_DIR)/events.csv" "$(INJECT)"

swarm-sql-check: ## Swarm server: run the stock invariant check after a concurrency experiment (RESULTS_DIR holding sql-check-vars.txt)
	@set -eu; vars="$(RESULTS_DIR)/sql-check-vars.txt"; \
	test -f "$$vars" || { echo "Missing $$vars: copy the k6 results of the concurrency run to RESULTS_DIR first" >&2; exit 1; }; \
	$(swarm_load_env); \
	rc=0; docker exec -i $(call swarm_cid,postgres) psql -q -U "$$DB_USERNAME" -d "$$DB_DATABASE" $$(cat "$$vars") -f - \
		< $(LOAD_DIR)/sql/stock-consistency.sql > "$(RESULTS_DIR)/sql-check.txt" 2>&1 || rc=$$?; \
	cat "$(RESULTS_DIR)/sql-check.txt"; \
	if [ "$$rc" -ne 0 ]; then echo "stock consistency check FAILED (exit $$rc)" >&2; fi; exit "$$rc"
