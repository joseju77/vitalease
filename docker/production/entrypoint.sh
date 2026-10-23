#!/bin/sh
# Maps *_FILE variables (Docker/Swarm secrets) to plain environment variables,
# warms the framework caches and hands over to the requested command.
# Migrations are NEVER run here: several replicas start at once and would race.
set -eu

for file_var in $(env | sed -n 's/^\([A-Z0-9_]*_FILE\)=.*/\1/p'); do
    target="${file_var%_FILE}"
    path="$(printenv "$file_var")"

    if [ -z "$path" ]; then
        continue
    fi

    if [ ! -r "$path" ]; then
        echo "entrypoint: $file_var points to an unreadable file" >&2
        exit 1
    fi

    # Assign first so a failed read aborts under `set -e` (export would mask it).
    value="$(cat "$path")"
    export "$target=$value"
done

# Config must be cached after secrets are in the environment (app/ has no env() calls).
php artisan optimize --no-interaction >/dev/null

exec "$@"
