#!/bin/sh
set -eu

if [ "$(id -u)" = "0" ]; then
    local_ca_added=0

    install_local_ca() {
        local_ca_file="$1"
        local_ca_name="$2"

        if [ ! -f "$local_ca_file" ]; then
            return
        fi

        if ! openssl x509 -in "$local_ca_file" -noout >/dev/null 2>&1; then
            echo "Local EIS CA file must contain a PEM X.509 certificate: $local_ca_name." >&2
            exit 1
        fi

        cp "$local_ca_file" "/usr/local/share/ca-certificates/$local_ca_name.crt"
        local_ca_added=1
    }

    if [ -n "${EIS_TRUSTED_CA_FILE:-}" ]; then
        install_local_ca "$EIS_TRUSTED_CA_FILE" "tender-finder-eis-custom-ca"
    else
        install_local_ca "/var/www/html/deploy/local-ca/russian-trusted-root-ca.crt" "tender-finder-eis-root-ca"
        install_local_ca "/var/www/html/deploy/local-ca/russian_trusted_root_ca_pem.crt" "tender-finder-eis-root-ca"
        install_local_ca "/var/www/html/deploy/local-ca/russian-trusted-sub-ca.crt" "tender-finder-eis-sub-ca"
        install_local_ca "/var/www/html/deploy/local-ca/russian_trusted_sub_ca_pem.crt" "tender-finder-eis-sub-ca"
    fi

    if [ "$local_ca_added" = "1" ]; then
        update-ca-certificates >/dev/null
    fi

    owner_marker="vendor/.tender-finder-owned-by-www-data"

    if [ ! -f "$owner_marker" ]; then
        chown -R www-data:www-data vendor
        touch "$owner_marker"
        chown www-data:www-data "$owner_marker"
    fi

    # A previously executed root-level test command may leave Pest's cache
    # unwritable for the isolated test service. Repair only that disposable
    # directory instead of traversing the whole vendor volume on every start.
    if [ -d "vendor/pestphp/pest/.temp" ]; then
        chown -R www-data:www-data vendor/pestphp/pest/.temp
    fi

    exec su-exec www-data "$0" "$@"
fi

# Production containers cache config and routes. Development must load edited
# configuration and routes on the next container restart instead. This comes
# before Composer because its hooks run Laravel package discovery.
rm -f bootstrap/cache/*.php 2>/dev/null || true

# Keep the development vendor volume in sync with composer.lock. The marker
# avoids reinstalling dependencies after ordinary PHP or React source edits.
lock_fingerprint="$(sha256sum composer.lock | awk '{print $1}')"
lock_marker="vendor/.tender-finder-composer-lock.sha256"

if [ ! -f "$lock_marker" ] || [ "$(cat "$lock_marker")" != "$lock_fingerprint" ]; then
    composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader
    printf '%s\n' "$lock_fingerprint" > "$lock_marker"
fi

php artisan package:discover --ansi

exec "$@"
