# Minimal database backups for the Swarm stack. Included after swarm.mk.
# Adds the backup overlay to every stack command and two operator targets.

SWARM_STACK_FILES += -c $(SWARM_DIR)/stack.backup.yml

# Content hash of the backup scripts: renames the immutable Swarm configs when they change.
swarm-deploy: export BACKUP_CONFIG_REV := $(shell cat $(SWARM_DIR)/backup/* | sha256sum | cut -c1-12)
swarm-deploy: export SWARM_STACK := $(SWARM_STACK)

.PHONY: swarm-backup swarm-restore-verify

swarm-backup: ## Swarm: take a database dump now (same script as the daily cron job)
	@docker exec $(call swarm_cid,backup) /usr/local/bin/backup.sh

swarm-restore-verify: ## Swarm: restore the newest dump (or FILE=/backups/x.dump) into a scratch DB and compare row counts
	@docker exec $(if $(FILE),-e FILE=$(FILE)) $(call swarm_cid,backup) /usr/local/bin/restore-verify.sh
