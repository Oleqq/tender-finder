#!/bin/sh
set -eu

# Railway injects PORT into the public web service. Do not use this process for
# workers or scheduler: each has its own long-running Railway service.
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}" --no-reload
