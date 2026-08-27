#!/bin/sh
set -eu

# A named node_modules volume survives restarts. Refresh it only when the lock
# file changes, while source edits stay available to Vite immediately.
lock_fingerprint="$(sha256sum package-lock.json | awk '{print $1}')"
lock_marker="node_modules/.tender-finder-package-lock.sha256"

if [ ! -f "$lock_marker" ] || [ "$(cat "$lock_marker")" != "$lock_fingerprint" ]; then
    npm ci
    printf '%s\n' "$lock_fingerprint" > "$lock_marker"
fi

exec "$@"
