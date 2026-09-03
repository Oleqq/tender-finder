#!/bin/sh
set -eu

# Deploy the already-reviewed main branch. Database migrations are deliberately
# not rolled back automatically: restore is a separate, reviewed operation.
project_dir=/opt/tenderfinder
cd "$project_dir"

git fetch --prune origin main
git pull --ff-only origin main
docker compose --env-file .env.production -f compose.production.yml build --pull
docker compose --env-file .env.production -f compose.production.yml --profile ops run --rm migrate
docker compose --env-file .env.production -f compose.production.yml up -d --remove-orphans
docker image prune -f
