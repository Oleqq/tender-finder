#!/bin/sh
set -eu

# Configure this only as the web service's Railway Pre-Deploy Command. Running
# migrations from worker and scheduler deployments would create unnecessary
# concurrent migration attempts.
php artisan migrate --force --no-interaction
