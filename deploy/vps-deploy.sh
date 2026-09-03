#!/bin/sh
set -eu

# GitHub Actions uploads the already-reviewed source to this directory before
# this script starts. The VPS intentionally has no credential for the private
# repository. Database migrations are deliberately not rolled back
# automatically: restore is a separate, reviewed operation.
project_dir=/opt/tenderfinder
cd "$project_dir"

docker compose --env-file .env.production -f compose.production.yml build --pull
docker compose --env-file .env.production -f compose.production.yml --profile ops run --rm migrate
docker compose --env-file .env.production -f compose.production.yml up -d --remove-orphans
docker image prune -f
