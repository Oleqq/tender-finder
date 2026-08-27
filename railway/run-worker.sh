#!/bin/sh
set -eu

# Redis-backed jobs only. RSS live polling remains disabled by application
# configuration until the product owner explicitly enables it.
exec php artisan queue:work redis --sleep=1 --tries=3
