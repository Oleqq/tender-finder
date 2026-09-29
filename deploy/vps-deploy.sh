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
docker compose --env-file .env.production -f compose.production.yml --profile ops build --pull
docker compose --env-file .env.production -f compose.production.yml --profile ops run --rm migrate
docker compose --env-file .env.production -f compose.production.yml up -d --remove-orphans
docker image prune -f
