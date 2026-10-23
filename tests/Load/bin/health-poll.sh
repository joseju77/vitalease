#!/bin/sh
# Polls the stack's health once per second into a CSV: the public /up on the
# published port and the internal /health report through the nginx task.
# Run on the Swarm server while an experiment is running.
#
# Usage: health-poll.sh <output.csv> [interval-seconds]
# Env:   STACK (default vitalease), PUBLIC_URL (default http://127.0.0.1)
set -eu

if [ "$#" -lt 1 ]; then
    echo "usage: health-poll.sh <output.csv> [interval-seconds]" >&2
    exit 2
fi

output="$1"
interval="${2:-1}"
stack="${STACK:-vitalease}"
public_url="${PUBLIC_URL:-http://127.0.0.1}"

case "$interval" in
    '' | *[!0-9]*) echo "health-poll: interval must be a whole number of seconds" >&2; exit 2 ;;
esac

if [ ! -s "$output" ]; then
    echo "timestamp_utc,up_status,up_ms,health_status,health_overall" > "$output"
fi

trap 'exit 0' INT TERM

while :; do
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    # Published listener: liveness only (/health is intentionally 404 here).
    up="$(curl -s -o /dev/null -m 3 -w '%{http_code} %{time_total}' "${public_url}/up" 2>/dev/null || echo '000 0')"
    up_status="${up%% *}"
    up_ms="$(awk -v seconds="${up##* }" 'BEGIN { printf "%d", seconds * 1000 }')"

    # Internal listener, reached through the nginx task (the port is not published).
    nginx_container="$(docker ps -q --filter "label=com.docker.swarm.service.name=${stack}_nginx" | head -n 1)"
    health_status=000
    health_overall=unreachable

    if [ -n "$nginx_container" ]; then
        # busybox wget -S prints the response headers to stderr; on an HTTP error
        # (503 when a critical dependency is down) it prints no body, so the status
        # line is the source of truth and the body is only parsed on success.
        response="$(docker exec "$nginx_container" wget -S -q -T 3 -O - http://127.0.0.1:8081/health 2>&1 || true)"
        health_status="$(printf '%s' "$response" | sed -n 's/^ *HTTP\/[0-9.]* \([0-9][0-9]*\).*/\1/p' | tail -n 1)"
        health_status="${health_status:-000}"

        case "$health_status" in
            200) health_overall="$(printf '%s' "$response" | sed -n 's/.*{"status":"\([a-z]*\)".*/\1/p' | head -n 1)" ;;
            503) health_overall=down ;;
            *) health_overall=unreachable ;;
        esac
        health_overall="${health_overall:-unparsed}"
    fi

    printf '%s,%s,%s,%s,%s\n' "$now" "$up_status" "$up_ms" "$health_status" "$health_overall" >> "$output"
    sleep "$interval"
done
