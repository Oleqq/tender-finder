#!/bin/sh
set -eu

# This file is invoked by the VPS systemd timer. It never prints credentials
# and retains exactly the most recent 30 daily database dumps.
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
backup_dir=${TENDER_FINDER_BACKUP_DIR:-/var/backups/tenderfinder/postgres}
timestamp=$(date -u +%Y-%m-%dT%H-%M-%SZ)
temporary_file="$backup_dir/.tender-finder-$timestamp.sql.gz.tmp"
final_file="$backup_dir/tender-finder-$timestamp.sql.gz"

umask 077
mkdir -p "$backup_dir"

cd "$project_dir"
docker compose --env-file .env.production -f compose.production.yml exec -T db \
    sh -c 'exec pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip -9 > "$temporary_file"

test -s "$temporary_file"
mv "$temporary_file" "$final_file"
find "$backup_dir" -type f -name 'tender-finder-*.sql.gz' -mtime +30 -delete
