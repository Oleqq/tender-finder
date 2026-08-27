#!/bin/sh
set -eu

# A permanent service is intentional: it runs Laravel's schedule every minute.
exec php artisan schedule:work
