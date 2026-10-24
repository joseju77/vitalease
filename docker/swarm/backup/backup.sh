#!/bin/sh
# Takes a custom-format pg_dump of the application database into BACKUP_DIR and
# keeps only the newest BACKUP_KEEP dumps. Runs from crond and on demand
# (`make swarm-backup`). The password is read from a Docker secret file and is
# never printed. Scope: local volume only (no off-site copy, encryption or PITR).
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_KEEP="${BACKUP_KEEP:-7}"
PGHOST="${PGHOST:-postgres}"
PGUSER="${PGUSER:?PGUSER is required}"
PGDATABASE="${PGDATABASE:?PGDATABASE is required}"
PGPASSWORD="$(cat "${PGPASSWORD_FILE:-/run/secrets/db_password}")"
export PGHOST PGUSER PGDATABASE PGPASSWORD

mkdir -p "$BACKUP_DIR"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
final="$BACKUP_DIR/${PGDATABASE}_${stamp}.dump"
partial="$final.partial"

# A half-written dump must never look like a valid backup.
trap 'rm -f "$partial"' EXIT

pg_dump --format=custom --file="$partial"
mv "$partial" "$final"

size="$(wc -c <"$final" | tr -d ' ')"
echo "backup: created $final ($size bytes)"

# Timestamps in the names sort lexicographically; keep the newest N.
ls -1 "$BACKUP_DIR" | grep -E "^${PGDATABASE}_[0-9]{8}T[0-9]{6}Z\.dump\$" | sort -r | tail -n "+$((BACKUP_KEEP + 1))" |
    while read -r old; do
        rm -f -- "$BACKUP_DIR/$old"
        echo "backup: pruned $old"
    done
