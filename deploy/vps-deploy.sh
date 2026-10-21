#!/bin/sh
set -eu

# GitHub Actions uploads the already-reviewed source to this directory before
# this script starts. The VPS intentionally has no credential for the private
# repository. Database migrations are deliberately not rolled back
# automatically: restore is a separate, reviewed operation.
project_dir=/opt/tenderfinder
cd "$project_dir"

# Telegram's IPv4 endpoint may be unreachable from the VPS while IPv6 works.
# Give app containers a second, dual-stack bridge without changing the private
# database network or publishing database/Redis ports.
egress_network=tender-finder-egress-v6
if docker network inspect "$egress_network" >/dev/null 2>&1; then
    if [ "$(docker network inspect "$egress_network" --format '{{.EnableIPv6}}')" != true ]; then
        echo "Existing $egress_network network has no IPv6 support" >&2
        exit 1
    fi
else
    docker network create --ipv6 "$egress_network" >/dev/null
fi

# The migration service is behind the `ops` profile. Build it explicitly as
# well so a newly added migration never runs from a stale image.
if [ "${TENDER_FINDER_USE_LOCAL_PHP_BASE:-0}" = 1 ]; then
    # Emergency release path while Docker Hub has not published the official
    # patched tag. The build and runtime checks below still enforce 8.3.36+.
    docker image inspect php:8.3.36-cli-alpine >/dev/null
    docker compose --env-file .env.production -f compose.production.yml --profile ops build
else
    docker compose --env-file .env.production -f compose.production.yml --profile ops build --pull
fi
# Never run migrations or replace the live web container from an unpatched
# image, even if a registry mirror reused an old tag.
docker compose --env-file .env.production -f compose.production.yml --profile ops run --rm --no-deps --entrypoint php migrate \
    -r 'if (PHP_VERSION_ID < 80336 || PHP_VERSION_ID >= 80400 || ini_get("session.upload_progress.enabled")) { fwrite(STDERR, "Patched PHP 8.3 and disabled upload progress are required\n"); exit(1); } echo PHP_VERSION, " verified\n";'
docker compose --env-file .env.production -f compose.production.yml --profile ops run --rm migrate
docker compose --env-file .env.production -f compose.production.yml up -d --remove-orphans
docker image prune -f
