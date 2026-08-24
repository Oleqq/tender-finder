#!/bin/sh
set -eu

# Configuration is built inside each immutable app container. Secrets remain in
# the VPS secret store / .env.production and are never printed by this script.
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

exec "$@"
