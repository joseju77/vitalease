#!/bin/sh
# Samples `docker stats` for every service of the stack into one CSV (run on the
# Swarm server, next to the Docker daemon). One row per container per sample.
#
# Usage: docker-stats.sh <output.csv> [pause-seconds]
# One `docker stats` call takes about 1 s, so the default pause of 1 s gives a
# sample roughly every 2 s.
# Env:   STACK (default vitalease)
# Stop with SIGTERM/SIGINT (the Make target sends SIGTERM through the pid file).
set -eu

if [ "$#" -lt 1 ]; then
    echo "usage: docker-stats.sh <output.csv> [pause-seconds]" >&2
    exit 2
fi

output="$1"
interval="${2:-1}"
stack="${STACK:-vitalease}"

case "$interval" in
    '' | *[!0-9]*) echo "docker-stats: pause must be a whole number of seconds" >&2; exit 2 ;;
esac

if [ ! -s "$output" ]; then
    echo "timestamp_utc,service,container,cpu_percent,mem_usage,mem_limit,mem_percent,net_io,block_io,pids" > "$output"
fi

trap 'exit 0' INT TERM

while :; do
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    containers="$(docker ps -q --filter "label=com.docker.stack.namespace=${stack}")"
    if [ -z "$containers" ]; then
        sleep "$interval"
        continue
    fi
    # `docker stats --no-stream` already waits ~1 s per call to compute the CPU delta.
    docker stats --no-stream \
        --format '{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.MemPerc}}|{{.NetIO}}|{{.BlockIO}}|{{.PIDs}}' \
        $containers 2>/dev/null |
        while IFS='|' read -r name cpu mem memp net block pids; do
            # Swarm task containers are named <stack>_<service>.<slot>.<task id>.
            service="${name%%.*}"
            used="${mem%% / *}"
            limit="${mem##* / }"
            printf '%s,%s,%s,%s,%s,%s,%s,"%s","%s",%s\n' \
                "$now" "$service" "$name" "${cpu%\%}" "$used" "$limit" "${memp%\%}" "$net" "$block" "$pids" >> "$output"
        done || true
    sleep "$interval"
done
