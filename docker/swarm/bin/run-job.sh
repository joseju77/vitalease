#!/bin/sh
# Runs a one-off command as a Swarm replicated-job using the exact image, secrets
# and environment of the running "app" service, waits for it, prints its logs,
# removes it and exits non-zero when the job failed.
#
# Usage: run-job.sh <job-name> <command> [args...]
# Env:   STACK (default vitalease), JOB_TIMEOUT seconds (default 7200),
#        JOB_IMAGE_REPO (optional: run this repository with the app service's tag,
#        e.g. vitalease/seeder, so the job always matches the deployed release)
set -eu

if [ "$#" -lt 2 ]; then
    echo "usage: run-job.sh <job-name> <command> [args...]" >&2
    exit 2
fi

stack="${STACK:-vitalease}"
timeout="${JOB_TIMEOUT:-7200}"
app_service="${stack}_app"
job_name="${stack}_job_$1_$(date +%s)"
shift

if ! docker service inspect "$app_service" >/dev/null 2>&1; then
    echo "run-job: service $app_service not found; deploy the stack first" >&2
    exit 1
fi

work_dir="$(mktemp -d)"
cleanup() {
    docker service rm "$job_name" >/dev/null 2>&1 || true
    rm -rf "$work_dir"
}
trap cleanup EXIT INT TERM

# Only *_FILE paths (never secret values) are part of the service environment.
docker service inspect \
    --format '{{range .Spec.TaskTemplate.ContainerSpec.Env}}{{println .}}{{end}}' \
    "$app_service" | sed '/^$/d' >"$work_dir/env"

image="$(docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' "$app_service")"
image="${image%%@*}"
if [ -n "${JOB_IMAGE_REPO:-}" ]; then
    image="${JOB_IMAGE_REPO}:${image##*:}"
fi

docker service inspect \
    --format '{{range .Spec.TaskTemplate.ContainerSpec.Secrets}}{{.SecretName}} {{.File.Name}}{{println}}{{end}}' \
    "$app_service" | sed '/^$/d' >"$work_dir/secrets"

# `docker service create [OPTIONS] IMAGE [COMMAND] [ARG...]`: put the image in
# front of the command, then prepend every option so the final order is valid.
set -- "$image" "$@"
while read -r secret_name secret_target; do
    set -- --secret "source=$secret_name,target=$secret_target" "$@"
done <"$work_dir/secrets"

echo "run-job: creating $job_name from $image"
docker service create \
    --detach \
    --name "$job_name" \
    --mode replicated-job \
    --restart-condition none \
    --network "${stack}_internal" \
    --limit-cpu 1.0 --limit-memory 1536M \
    --reserve-cpu 0.25 --reserve-memory 256M \
    --env-file "$work_dir/env" \
    --env MEILISEARCH_CONNECT_TIMEOUT=2 \
    --env MEILISEARCH_TIMEOUT=15 \
    --no-resolve-image \
    "$@" >/dev/null

elapsed=0
state=""
while [ "$elapsed" -lt "$timeout" ]; do
    state="$(docker service ps "$job_name" --format '{{.CurrentState}}' | head -n 1)"
    case "$state" in
        Complete*|Failed*|Rejected*|Shutdown*|Orphaned*)
            break
            ;;
    esac
    sleep 3
    elapsed=$((elapsed + 3))
done

echo "run-job: ---- logs ----"
docker service logs --no-task-ids --raw "$job_name" 2>&1 || true
echo "run-job: ---- end of logs ----"

case "$state" in
    Complete*)
        echo "run-job: $job_name completed"
        ;;
    *)
        echo "run-job: $job_name did not complete (last state: ${state:-unknown})" >&2
        exit 1
        ;;
esac
