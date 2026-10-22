# PHP security update, 21 October 2026

PHP 8.3 releases before 8.3.36 are affected by two critical RCE issues,
CVE-2026-109618 and CVE-2026-109619. The application image built before this
change used the moving `php:8.3-cli-alpine` tag; an existing local image was
PHP 8.3.33. The Dockerfile now requires `php:8.3.36-cli-alpine`, checks the
actual version during the build, and disables session upload progress. The VPS
deploy script repeats both checks before any migration or container replacement.

Sources: [PHP 8.3.36 release](https://www.php.net/releases/8_3_36.php),
[OOM advisory](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w),
[stale state advisory](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp).

## Immediate VPS protection if the patched Docker image is unavailable

At 14:13 UTC on 21 October, Docker Hub had not yet published
`php:8.3.36-cli-alpine`. A failed build **does not update the running VPS**.
Until the patched image can be built and deployed:

1. Copy the updated `deploy/Caddyfile` to `/opt/tenderfinder/deploy/Caddyfile`
   on the VPS. Set `PHP_BLOCK_MULTIPART=true` in the existing
   `/opt/tenderfinder/.env.production` without changing any other secrets.
2. From `/opt/tenderfinder`, recreate only Caddy (no application build):

   ```sh
   docker compose --env-file .env.production -f compose.production.yml up -d --no-deps --force-recreate caddy
   ```

3. Confirm a harmless multipart request is rejected with HTTP 503 before it
   reaches PHP, while `/health` still returns 200:

   ```sh
   curl -sS -o /dev/null -w '%{http_code}\n' -F probe=1 "$(sed -n 's/^APP_URL=//p' .env.production | head -n 1)/health"
   curl -sS -o /dev/null -w '%{http_code}\n' "$(sed -n 's/^APP_URL=//p' .env.production | head -n 1)/health"
   ```

   Document uploads are unavailable while this switch is enabled. This proxy
   restriction is only a partial mitigation; the PHP advisories say that
   upgrading is the only complete fix.

The emergency switch can be used without the optional PHP setting below. If
the web container itself is accessible, also disable the session upload
progress feature in that running container and restart it. This is temporary
and will be lost if the old image is recreated:

```sh
docker compose --env-file .env.production -f compose.production.yml exec -T -u root web sh -c 'printf "session.upload_progress.enabled=0\n" > /usr/local/etc/php/conf.d/zz-disable-upload-progress.ini'
docker compose --env-file .env.production -f compose.production.yml restart web
docker compose --env-file .env.production -f compose.production.yml exec -T web php -r 'echo PHP_VERSION, " upload_progress=", ini_get("session.upload_progress.enabled"), "\n";'
```

On 21 October the VPS was confirmed to run PHP 8.3.35. The multipart switch
was enabled and checked externally (`503` for multipart, `200` for `/health`),
and upload progress was set to `0` in the running web container. Those were
temporary mitigations until the patched image was deployed on 22 October.

## Complete update

When `docker manifest inspect php:8.3.36-cli-alpine` succeeds, deploy the
updated revision with `sh deploy/vps-deploy.sh`. The script refuses to replace
the live service if its new image does not have patched PHP and disabled upload
progress. Confirm the running version and health:

```sh
docker compose --env-file .env.production -f compose.production.yml exec -T web php -r 'echo PHP_VERSION, " upload_progress=", ini_get("session.upload_progress.enabled"), "\n";'
curl -fsS "$(sed -n 's/^APP_URL=//p' .env.production | head -n 1)/health"
```

After confirming PHP 8.3.36 or a newer 8.3 patch release, set
`PHP_BLOCK_MULTIPART=false` in `.env.production` (or remove the key), recreate
only Caddy as above, and verify document uploads again. Do not treat the
source change or a successful Git push alone as a production update.

If Docker Hub still lacks the tag, build `php:8.3.36-cli-alpine` from the
[official Docker recipe](https://github.com/docker-library/php/tree/e3eaff845f7a9f7259c31da54dce0320b47a3bbd/8.3/alpine3.23/cli)
using the signed archive from
[PHP's distribution repository](https://github.com/php/web-php-distributions).
Verify its [published SHA-256](https://www.php.net/downloads.php?source=Y)
and GPG signature. The deployment script then supports
`TENDER_FINDER_USE_LOCAL_PHP_BASE=1 sh deploy/vps-deploy.sh`; this skips a
Docker Hub pull only for that explicit run and still rejects an old PHP image
before migrations or service replacement. Normal releases continue to pull
the base image.

## Production result, 22 October 2026

The official `php:8.3.36-cli-alpine` tag became available. Revision `eb3b2a0`
was built and deployed with the normal pull path in `deploy/vps-deploy.sh`.
The script verified PHP 8.3.36 and disabled upload progress before running
migrations (`Nothing to migrate`) and replacing the services. Runtime checks
confirmed `8.3.36 upload_progress=0` in `web`, `queue`, and `scheduler`.

All six Compose services were running; `web`, PostgreSQL, and Redis were
healthy. Public HTTPS `/health` and `/open` returned 200. After the patched
containers were live, `PHP_BLOCK_MULTIPART=false` was set and Caddy recreated.
A harmless multipart POST to the GET-only `/health` route then returned 405,
confirming that the temporary proxy block no longer intercepted it. This
does not replace an authenticated document-upload test. A verified database
backup exists at
`/var/backups/tenderfinder/postgres/tender-finder-2026-10-21T14-44-37Z.sql.gz`,
and the previous application tree was kept at
`/opt/tenderfinder-pre-eb3b2a0` for source-level rollback.
