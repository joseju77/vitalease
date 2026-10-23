#!/bin/sh
# Injects one failure into the running stack and logs timestamped events to
# events.csv, for the failure-injection experiment (run on the Swarm server).
# The matching "recovered" row is written when the service is healthy again.
#
# Usage: inject.sh <events.csv> <action>
# Actions:
#   kill-app        kill one app task (Swarm replaces it)
#   meilisearch-0   scale meilisearch to 0 ...
#   meilisearch-1   ... and back to 1
#   redis-0 / redis-1       same for redis
#   postgres-0 / postgres-1 same for postgres
#   restart-nginx   force-restart the nginx service
# Env: STACK (default vitalease), RECOVERY_TIMEOUT seconds (default 300)
set -eu

if [ "$#" -ne 2 ]; then
    echo "usage: inject.sh <events.csv> <action>" >&2
    exit 2
fi

events="$1"
action="$2"
stack="${STACK:-vitalease}"
recovery_timeout="${RECOVERY_TIMEOUT:-300}"

log_event() {
    if [ ! -s "$events" ]; then
        echo "timestamp_utc,event,detail" > "$events"
    fi
    printf '%s,%s,%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2" >> "$events"
}

# Waits until every desired replica of a service is running; returns non-zero on timeout.
wait_replicas() {
    service="$1"
    waited=0
    while [ "$waited" -lt "$recovery_timeout" ]; do
        replicas="$(docker service ls --filter "name=${stack}_${service}" --format '{{.Replicas}}' | head -n 1)"
        running="${replicas%%/*}"
        desired="${replicas#*/}"
        desired="${desired%% *}"
        if [ -n "$running" ] && [ "$running" = "$desired" ] && [ "$desired" != "0" ]; then
            return 0
        fi
        sleep 1
        waited=$((waited + 1))
    done
    return 1
}

scale() {
    service="$1"
    count="$2"
    log_event "scale_${service}_${count}" "docker service scale ${stack}_${service}=${count}"
    docker service scale "${stack}_${service}=${count}" > /dev/null
}

case "$action" in
    kill-app)
        container="$(docker ps -q --filter "label=com.docker.swarm.service.name=${stack}_app" | head -n 1)"
        if [ -z "$container" ]; then
            echo "inject: no running app task found" >&2
            exit 1
        fi
        log_event kill_app "container ${container}"
        docker kill "$container" > /dev/null
        if wait_replicas app; then log_event recovered app; else log_event recovery_timeout app; fi
        ;;
    meilisearch-0 | redis-0 | postgres-0)
        scale "${action%-0}" 0
        ;;
    meilisearch-1 | redis-1 | postgres-1)
        service="${action%-1}"
        scale "$service" 1
        if wait_replicas "$service"; then log_event recovered "$service"; else log_event recovery_timeout "$service"; fi
        ;;
    restart-nginx)
        log_event restart_nginx "docker service update --force ${stack}_nginx"
        docker service update --force "${stack}_nginx" > /dev/null
        if wait_replicas nginx; then log_event recovered nginx; else log_event recovery_timeout nginx; fi
        ;;
    *)
        echo "inject: unknown action '${action}'" >&2
        exit 2
        ;;
esac
