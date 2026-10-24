#!/bin/sh
# Restores the newest dump (or FILE=...) into a scratch database, compares row
# counts of the key tables with the live database, prints duration and dump size,
# and exits 1 on any mismatch. The live database is only read. Precondition: no
# write load while it runs, otherwise counts legitimately differ.
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
PGHOST="${PGHOST:-postgres}"
PGUSER="${PGUSER:?PGUSER is required}"
PGDATABASE="${PGDATABASE:?PGDATABASE is required}"
SCRATCH_DB="${SCRATCH_DB:-vitalease_restore_check}"
PGPASSWORD="$(cat "${PGPASSWORD_FILE:-/run/secrets/db_password}")"
export PGHOST PGUSER PGPASSWORD

TABLES="users patients medical_consultations medications inventory_movements audits"

if [ "$SCRATCH_DB" = "$PGDATABASE" ]; then
    echo "restore-verify: refusing to use the live database as scratch" >&2
    exit 2
fi

dump="${FILE:-}"
if [ -z "$dump" ]; then
    newest="$(ls -1 "$BACKUP_DIR" 2>/dev/null | grep -E '\.dump$' | sort -r | head -n 1 || true)"
    if [ -z "$newest" ]; then
        echo "restore-verify: no dump found in $BACKUP_DIR" >&2
        exit 2
    fi
    dump="$BACKUP_DIR/$newest"
fi

if [ ! -r "$dump" ]; then
    echo "restore-verify: cannot read $dump" >&2
    exit 2
fi

cleanup() {
    dropdb --if-exists "$SCRATCH_DB" >/dev/null 2>&1 || true
}
trap cleanup EXIT

dropdb --if-exists "$SCRATCH_DB"
createdb "$SCRATCH_DB"

started="$(date +%s)"
pg_restore --no-owner --exit-on-error --dbname="$SCRATCH_DB" "$dump"
duration="$(($(date +%s) - started))"
size="$(wc -c <"$dump" | tr -d ' ')"

status=0
printf '%-26s %12s %12s\n' table live restored
for table in $TABLES; do
    live="$(psql -X -At -d "$PGDATABASE" -c "SELECT count(*) FROM \"$table\"")"
    restored="$(psql -X -At -d "$SCRATCH_DB" -c "SELECT count(*) FROM \"$table\"")"
    if [ "$live" = "$restored" ]; then
        result=ok
    else
        result=MISMATCH
        status=1
    fi
    printf '%-26s %12s %12s  %s\n' "$table" "$live" "$restored" "$result"
done

echo "restore-verify: dump=$dump size=${size}B restore_seconds=$duration"
if [ "$status" -eq 0 ]; then
    echo "restore-verify: PASS"
else
    echo "restore-verify: FAIL (row counts differ)" >&2
fi
exit "$status"
