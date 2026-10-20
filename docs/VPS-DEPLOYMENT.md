# Tender Finder: VPS deployment

> Дополнение 20 октября 2026: ревизия `140b1a9` с HTTP-логами, платёжными
> расписаниями и оповещениями выпущена на VPS. `/health` и `/open` ответили
> `200`; сверка Caddy/Laravel по `request_id` прошла. Затем UI-коммит
> `a8ae797` прошёл CI в обоих репозиториях и выпущен: `/health` и новый
> JS-файл Plans ответили `200`. Stars пока выключены.

## Проверка доступности 19 октября 2026

Сначала целевой VPS не отвечал на SSH до баннера и на публичный HTTPS до TLS.
Позже SSH восстановился; перед релизом копия
`postgres-20261018T233217Z.sql.gz` прошла `gzip -t` и сохранила режим 600.
Точный архив `447c52a` совпал по SHA-256 локально и на VPS. Стандартный
`vps-deploy.sh` собрал контейнеры и применил Stars-миграцию. Сейчас шесть
сервисов запущены, `web`, PostgreSQL и Redis здоровы, очередь пуста,
внутренний HTTPS `/health` отвечает 200; релизный маркер обновлён до
`447c52a`, `.env.production` остался с режимом 600 и не заменялся.
Затем GitHub Actions run `39178205595` успешно установил `8032e04`:
миграций больше не было, `web`, PostgreSQL и Redis снова стали healthy.
Workflow не переписывает `.release-commit`, поэтому маркер остался на
`447c52a`; фактическая ревизия подтверждена checkout SHA и успешным deploy
run `39178205595`. Обновление маркера и добавление автоматической записи SHA
в workflow остаются операционными задачами.
Публичный HTTPS с рабочей станции нестабилен: часть проверок ответила 200,
часть зависла до TLS. Ручной Telegram smoke-test нового экрана нужно
закончить после разблокировки macOS.

## Current status

On 16 October 2026, two colleague Telegram user IDs and then a third owner
requested ID were added to the existing production `TELEGRAM_SUPERADMIN_IDS`
setting. The setting and legacy owner ID were previously empty. The production
environment file stayed on the VPS with mode `600` and was not committed.
Fresh PostgreSQL backups passed `gzip -t` before each change. The `web`,
`queue`, and `scheduler` containers were recreated so their entrypoints rebuilt
the Laravel config cache. Runtime checks confirmed all three IDs are
configured, local full-access mode is off, and the operations and admin
support routes carry server-side `super_admin` middleware. The third account
currently retains its subscriber role until its next verified Telegram login;
the other two accounts do not yet exist. Personal Telegram acceptance remains
pending for all three.

The production stack is one Docker Compose application: Caddy manages HTTPS;
Laravel `web`, `queue`, and `scheduler` run separately; PostgreSQL and Redis
are private Docker services with persistent volumes. A systemd timer creates a
compressed PostgreSQL backup every day at 03:15 UTC and removes backups older
than 30 days. The current backup directory is `/opt/tenderfinder/backups`.

The release script creates the external dual-stack Docker bridge
`tender-finder-egress-v6` when needed. `web`, `queue`, and `scheduler` join it
alongside the private application network. This gives Telegram API calls an
IPv6 route when the VPS IPv4 path to Telegram is unavailable. PostgreSQL and
Redis remain on the private network and have no published ports.

The technical production address is `https://200.165.238.247.sslip.io`. It is
not a business domain and should be replaced before public promotion. The old
VPS is retained unchanged and is not part of this deployment target.

The VPS is updated through the GitHub Actions workflow named **Deploy to VPS**.
It is triggered manually (`workflow_dispatch`) after both repositories pass CI.
The four deploy secrets are configured in the primary repository. A push alone
does not change production.

Once the secrets exist, a maintainer starts **Deploy to VPS** for a green
commit on `main`. Actions transfers that exact revision over SSH and calls
`/opt/tenderfinder/deploy/vps-deploy.sh`. The VPS has no credential for the
private GitHub repository. The script builds containers, runs forward-only
migrations, starts the services, and removes unused image layers. The current
workflow does not write `.release-commit`; inspect the Actions checkout SHA
for the exact released revision. Shell scripts
are normalized to LF by `.gitattributes` so the deployment script is portable
between Windows worktrees and the Linux VPS.

## Release 16 October 2026: Bidzaar sync and Telegram visibility

The colleague's four Bidzaar commits through `8e978c4` were fast-forwarded
into local `main`, pushed to both GitHub repositories, and verified against
the already updated VPS. Production had Bidzaar enabled and polling every
15 minutes. Its first import was intentionally silent; seven matches from
that initial snapshot produced no notification deliveries.

Commit `9d6169b` disables entry animations in Telegram WebView because
Telegram Desktop could leave animated cards transparent. CI passed in both
repositories. Before deployment, backup `postgres-20261016T051902Z.sql.gz`
passed `gzip -t` with mode `600`. The release archive checksum matched on
the VPS. The standard release script rebuilt the images, reported `Nothing
to migrate`, and restarted all services. All six containers are running;
web, PostgreSQL and Redis are healthy, external HTTPS `/health` returned 200,
and `.env.production` retained mode `600`. After reopening the real Telegram
Mini App, the feed, profile, monitorings and overview rendered their cards
immediately. A new matching Bidzaar purchase has not yet occurred, so
end-to-end personal notification delivery for this source is still pending.

## Release 14 October 2026: mobile UI and source diagnosis

Commit `f15a707` includes the colleague's mobile spacing changes from
`24f6fa0` and updated RosTender, Sber AST and current-state documentation.
CI passed in both GitHub repositories. A fresh PostgreSQL backup
`postgres-20261014T041158Z.sql.gz` passed `gzip -t`; the release tar SHA-256
matched locally and on the VPS. The standard deploy script built the images,
reported `Nothing to migrate`, and restarted web, queue and scheduler. All six
services are running; web, PostgreSQL and Redis are healthy, and external
HTTPS `/health` returned 200. The released CSS and integration document hashes
match the local commit. `.env.production` retained mode `600`.

The VPS's separate `/usr/local/sbin/tenderfinder-backup` script had been
creating files with mode `644` inside a root-only `700` backup directory.
Its `umask` is now `077`, and existing PostgreSQL dumps were set to mode
`600`; the new backup was verified at mode `600`. The RosTender feed still
reports `remote_quota_or_access_denied` and the direct Sber AST feed remains
off. See [ROSTENDER-INTEGRATION](ROSTENDER-INTEGRATION.md) and
[SBER-AST-SOURCE](SBER-AST-SOURCE.md) for the external blockers.

## Release 7 October 2026: website search relevance

Follow-up release `47f2e5f` passed CI in both repositories. A fresh backup
`tender-finder-2026-10-07T14-56-06Z.sql.gz` passed `gzip -t` and retained mode
`600`. The tracked archive checksum matched on the build host and VPS. The
standard deploy script completed; no migration was pending. Web, PostgreSQL
and Redis are healthy, the Redis default queue is empty, and HTTPS `/health`
returned `ok`. Real Telegram Desktop navigation confirmed the active search
hint and the expired-deadline badge in both feed and tender detail. The daily
digest now uses consecutive windows anchored to the selected local minute and
skips tenders whose deadline has passed. Previous delivery history is kept;
the next scheduled digest still needs a live observation.

Commit `ad96d45` passed CI in both repositories. A fresh PostgreSQL backup
passed `gzip -t` and had mode `600`; the tracked release archive's SHA-256
matched on the build host and VPS. The standard `sh deploy/vps-deploy.sh`
finished with `Nothing to migrate` and restarted the application services.
Web, PostgreSQL and Redis are healthy; HTTPS `/health` returned `ok` from the
VPS and Redis queue depth was zero. The production matcher rejected exactly
two previously stored website-builder software procurements while retaining
five relevant matches for the active website-development search. Those two
derived links were removed after checking there was no user state or
participation. Tender records and sent-delivery history were preserved.
The existing `.env.production` was not replaced and retained mode `600`.
Visual Telegram acceptance and a controlled new-card delivery remain open.

## Release 2 October 2026: Telegram Mini App session

Commit `9cf4c24` passed CI in both repositories before the manual release.
The fresh PostgreSQL backup passed `gzip -t`, and the tracked source archive
had the same SHA-256 on the build host and VPS. The existing `.env.production` was not
replaced: only `TELEGRAM_INIT_DATA_MAX_AGE_SECONDS` changed from `300` to
`86400`; mode remained `600`. `sh deploy/vps-deploy.sh` completed with
`Nothing to migrate`. Caddy, web, queue, scheduler, PostgreSQL and Redis are
running; queue depth is zero. The running app reports the new limit and
accepts a valid 12-hour-old signed payload without creating an account.
External HTTPS `/health` returned 200, while some connections from an external network
timed out before the TLS handshake. Local and public-path requests issued on
the VPS returned 200 quickly. The remote network-path issue remains under
observation.

## Release 1 October 2026: focused keyword search

Commit `64434a1` corrected unrelated matches caused by Workspace.ru service
categories and mixed personal topics. The source archive SHA-256 matched on
the build host and VPS; it did not contain `.env.production`. Backup
`tender-finder-2026-10-01T16-12-25Z.sql.gz` passed `gzip -t` and retained
mode `600`. The standard `sh deploy/vps-deploy.sh` completed; there were no
new migrations. All six services run; web, PostgreSQL and Redis are healthy,
PG accepts connections, Redis returns `PONG`, migration status is `Ran`, and
the external HTTPS `/health` returned 200. `.env.production` remained mode
`600`.

After a read-only preflight, two stale derived matches without user state or
participation were removed. Original cards were retained. The current active
monitoring match count is two; Workspace.ru and B2B-Center scheduled checks
remain registered.

## Release 1 October 2026: multi-source search

Commit `d930ec0` passed CI in both repositories and was installed using the
standard `sh deploy/vps-deploy.sh`. Before release, PostgreSQL backup
`tender-finder-2026-10-01T15-40-55Z.sql.gz` passed `gzip -t` and mode `600`;
the tracked source archive SHA-256 matched on both hosts. Only the existing
Workspace.ru and B2B-Center enabled flags were changed in production; the
other environment entries were preserved, and `.env.production` kept mode
`600`. The release built all images, reported `Nothing to migrate`, restarted
web, queue, scheduler and Caddy, and left PostgreSQL/Redis healthy.

External HTTPS `/health` returned HTTP 200 `ok`; PostgreSQL accepted
connections, Redis answered `PONG`, all migrations are `Ran`. One active
monitoring was linked to shared public feeds. Initial successful source runs
imported 21 B2B-Center and 50 Workspace.ru cards and created 1 and 2
personal matches.
Sber AST remained disabled because its public registry timed out from VPS.
Roseltorg and RTS-Tender clients were not part of this release.

## Earlier verified release: 30 September 2026

On 30 September 2026, commit `9f27f15` changed the personal search button
into monitoring creation and showed source delays in the feed. CI passed in
both repositories. Backup `tender-finder-2026-09-30T14-22-16Z.sql.gz` passed
`gzip -t` and retained mode `600`; the source archive checksum matched on
the VPS. The standard `sh deploy/vps-deploy.sh` reported `Nothing to migrate`
and started all six services. Web, PostgreSQL and Redis were healthy;
PostgreSQL accepted connections, Redis returned `PONG`, all migrations were
`Ran`, and the new quick-search route was registered. Production `.env` kept
mode `600`. HTTPS `/health` returned `ok` from the VPS with certificate
verification. External TLS attempts from the external test network timed out before an HTTP
response. RosTender remained in `quota_exhausted` cooldown, so live new-card
delivery still needs a beta test after quota recovery.

Later on 30 September 2026, commit `5ce07c3` fixed the subscriber tender
detail route found during the full UX flow check. Both repositories' CI
passed. Backup `postgres-20260930T111310Z.sql.gz` passed `gzip -t`, and the
new source archive checksum matched on the VPS. The standard deployment
script built the images, reported `Nothing to migrate`, and restarted all
six services. Web was healthy, PostgreSQL accepted connections, Redis returned
`PONG`, migration status showed `Ran`, and `.env.production` remained `600`.
The external HTTPS `/health` returned `ok`; a VPS-local request confirmed
the public onboarding route and that the new tender detail route redirects
unauthenticated visitors to onboarding. A matched card still needs visual
acceptance from a real Telegram session.

On 30 September 2026, commit `8a2e1ce` simplified the first-run workflow.
CI passed in both repositories. Backup
`postgres-20260930T104835Z.sql.gz` passed `gzip -t`, and the committed source
archive SHA-256 matched on the VPS. The standard `sh deploy/vps-deploy.sh`
completed its Docker build, forward-only migration step (`Nothing to
migrate`), container restart and image cleanup. Web, queue, scheduler,
PostgreSQL, Redis and Caddy were running; PostgreSQL was ready, Redis returned
`PONG`, migration status showed `Ran`, and `.env.production` retained mode
`600`. External HTTPS `/health` returned `ok`, and `/onboarding` returned
200 on retry. Authenticated application routes redirected unauthenticated
requests to onboarding from the VPS. Intermittent external TLS timeouts from
the build host still occur. Visual acceptance inside Telegram and a real first
RosTender search remain closed beta checks.

On 30 September 2026, commit `57017c7` delivered the RosTender quota
cooldown. Both repositories' CI passed. Backup
`postgres-20260930T084130Z.sql.gz` passed `gzip -t`; the tracked source
archive checksum matched on the VPS, and `.env.production` retained mode
`600`. The standard `deploy/vps-deploy.sh` stopped during its `--pull` build
because Docker Hub returned HTTP 429. No running service had been replaced.
Official `linux/amd64` base images were downloaded on the build host, transferred
with a verified checksum, and loaded into the VPS cache. The script's build,
migrate, start and prune steps were then executed without `--pull`; the
migration step reported no pending migrations. Production smoke confirmed
that a quota-exhausted RosTender poll left the successful-request counter at
200, set the next poll to 1 October 00:05 Moscow time, and did not dispatch
the cooled-down feed again. External HTTPS `/health`, all Compose services,
PostgreSQL, Redis and `php artisan migrate:status` passed. The real Telegram
Mini App and next-day quota recovery remain closed beta checks.

On 29 September 2026, commit `ad2d42d` delivered audited permanent Pro grants
and Telegram notices for manual access changes. Commit `17bb334` then added a
dual-stack Docker egress network because the VPS IPv4 path to Telegram timed
out while IPv6 worked. Both commits passed CI in both repositories and were
released through `deploy/vps-deploy.sh`. Before the second release, backup
`postgres-20260929T154853Z.sql.gz` passed `gzip -t`; the source archive
checksum matched, `.env.production` retained mode `600`, and no migrations
were pending. The new `web`, `queue`, and `scheduler` containers joined the
IPv6 bridge; a request from the recreated queue container reached Telegram's
IPv6 endpoint. PostgreSQL, Redis, all Compose services, and external HTTPS
`/health` passed checks. Two manual Pro grants remain active without expiry;
their two personal Telegram notices reached status `sent` after API responses.
Receipt on the users' devices still awaits closed beta confirmation. Incoming
SSH/HTTPS connections from the external test network intermittently stall before authentication
or TLS; investigate server/network reachability before expanding beta.

On 29 September 2026, commit `d32716b` completed the support workspace and
was released through `deploy/vps-deploy.sh`. CI was green in both repositories.
A fresh PostgreSQL backup (`postgres-20260929T135237Z.sql.gz`) passed
`gzip -t` before deployment. The tracked source archive checksum was verified
on the VPS before installation; `.env.production` stayed in place with mode
`600`, and `storage` and `backups` were preserved. The forward-only migration
`2026_09_29_150000_add_support_access_audit` is `Ran`. Web, queue, scheduler,
PostgreSQL, Redis and Caddy started; PostgreSQL accepted connections, Redis
returned `PONG`, support routes were listed and external HTTPS `/health`
returned `ok`. The first external request from the external test network timed out before a
repeat succeeded. Real Telegram beta acceptance remains open.

On 29 September 2026, commit `06c331b` was copied to the VPS as a tracked
source archive and released through `deploy/vps-deploy.sh`. Both repositories'
CI checks were green before the release. A fresh PostgreSQL backup was created
and checked with `gzip -t`. The archive did not contain `.env.production`;
the existing file was preserved with mode `600`. The forward-only migration
`2026_09_29_120000_add_support_tickets` ran successfully, and
`php artisan migrate:status` showed no pending migrations. The three support
tables exist in PostgreSQL. `web`, `queue`, `scheduler`, PostgreSQL, Redis and
Caddy were running, with zero container restarts and no recent application
error lines. The external HTTPS `/health` returned `ok`; authenticated support
routes were present. Real Telegram conversations still need closed beta
acceptance.

During this release, some new SSH and HTTPS connections from the external test network timed
out before the application handled a request. Subsequent external `/health`
checks succeeded, and HTTPS checks from the VPS itself succeeded consistently.
The cause of the intermittent external connection timeouts is not established;
check reachability from a second network before expanding the beta.

On 11 September 2026, commit `484fc98` was deployed through
`deploy/vps-deploy.sh` without replacing `.env.production`. It adds the
RosTender template-selection monitoring flow and source-scoped tender feed.
The production API smoke-test verified the enabled gates, an hourly polling
interval, and access to the configured template catalog; no secrets or tender
payloads were emitted. The public health endpoint remained healthy after the
restart.

On 11 September 2026, commit `4211242` was copied to the new VPS as a tracked
source archive, without replacing `.env.production`, and released through the
standard Compose script. Migration
`2026_09_10_120000_add_rostender_source_support` was then verified as applied.
The public health endpoint returned `{"status":"ok","application":"Tender Finder"}`
after the restart. Commit `b78ef27` subsequently made the release script build
the profiled `migrate` image before executing migrations; the GitHub Actions
release command invokes the script through `sh` so it is portable from Windows
checkouts.

## One-time owner inputs

These items cannot be safely guessed or created by deployment code:

1. A domain controlled by the business. `200.165.238.247.sslip.io` is the
   current temporary technical address; it is not a brand domain.
2. Approved XTR prices for Basic and Pro. The historic 990 ₽ / 2990 ₽ policy
   is not an exchange-rate instruction for Telegram Stars.
3. Public offer and privacy-policy URLs/versions. The current product copy is
   published for the present user-flow test; an owner and a lawyer must still
   approve commercial terms, operator details and document versions before a
   paid public launch.
4. The personal numeric Telegram IDs for administrators. Usernames and bot
   usernames are not substitutes.
5. A GitHub repository administrator must add the four Actions secrets below.
   This is intentionally a one-time GitHub authorization boundary; no secret
   belongs in Git history or in a workflow file.

## GitHub Actions secrets

| Secret | Value |
|---|---|
| `TENDER_FINDER_VPS_HOST` | VPS IP or final domain |
| `TENDER_FINDER_VPS_USER` | dedicated deploy user, not `root` |
| `TENDER_FINDER_VPS_DEPLOY_KEY` | private key for that user |
| `TENDER_FINDER_VPS_HOST_FINGERPRINT` | SSH SHA256 host-key fingerprint |

## Runtime secret file

`/opt/tenderfinder/.env.production` is created on the VPS with mode `600` and
is never committed. The minimum production topology uses:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR_DOMAIN
APP_DOMAIN=YOUR_DOMAIN
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=tender_finder
DB_USERNAME=tender_finder
DB_PASSWORD=<generated secret>
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
```

The production Compose stack sets `LOG_CHANNEL=stack`,
`LOG_STACK=json_stderr,json_daily` and `LOG_LEVEL=info`. Laravel emits JSON to
Docker logs and to a rotating 14-day file in the persistent `application_logs`
volume. Caddy writes JSON access logs to a persistent `caddy_logs` volume,
rolling at 10 MB and keeping at most five old files for up to 14 days. Docker
retains up to five 10 MB files per service while that container exists. To
inspect an incident, use
`docker compose -f compose.production.yml logs --since=30m web` for recent
Laravel events, or include `scheduler` for scheduled billing alerts:
`docker compose -f compose.production.yml logs --since=30m web scheduler`. For
older files, use
`docker compose -f compose.production.yml exec web ls storage/logs`.
Read Caddy access logs with
`docker compose -f compose.production.yml exec caddy tail -n 200 /var/log/caddy/access.log`.
Filter both by the `request_id` returned in `X-Request-ID`. Laravel entries include
method, route template, status, duration, user ID (when authenticated), and
coarse client platform. Caddy records path only for Mini App entry points and
static assets, plus status, duration and a masked client IP. Query strings,
request headers, response headers and bodies are omitted from access logs.
The bundled PHP development server also prints unstructured request lines;
these can contain query strings, so restrict access to Docker logs accordingly.
No HTTP log can show a Telegram WebView attempt that never reaches the VPS;
in that case compare the user's attempt time with the absence of `/open` in
Caddy logs.

Copy the remaining variables from `.env.example`. Store the Telegram token,
webhook secret, readiness token, and `APP_KEY` only in this file. Keep Stars
disabled until the approved XTR amounts are populated:

```dotenv
TELEGRAM_STARS_ENABLED=false
TELEGRAM_STARS_BASIC_PRICE_XTR=0
TELEGRAM_STARS_PRO_PRICE_XTR=0
```

Billing alerts use the existing `TELEGRAM_OWNER_ID` and
`TELEGRAM_SUPERADMIN_IDS` values. Each administrator must first message the bot.
After a release, test delivery without a payment from `/opt/tenderfinder`:

```bash
docker compose -f compose.production.yml exec web php artisan billing:check-stars-incidents --test-delivery
```

Check the result in every administrator's private chat. For a manually run
command, inspect its console output and Laravel's persistent `storage/logs`
files in `web`; inspect `scheduler` logs for scheduled alerts. Look for
`billing.alert.*`. The command returns a
failure status if any configured recipient could not be reached. See the
[Telegram Stars runbook](TELEGRAM-STARS-RUNBOOK.md#оповещения-и-проверка-доставки)
for incident response and the read-only reconciliation procedure.

## Release checks

1. Before every release, GitHub CI must be green: frontend build, PHP tests,
   PHPStan, Pint, ESLint and whitespace check.
2. Point the final domain's A record to the VPS and wait for DNS propagation.
3. Start the stack; Caddy obtains the TLS certificate after ports 80/443 are
   reachable from the Internet.
4. Check `https://YOUR_DOMAIN/health` and the private readiness endpoint.
5. Configure the bot's menu button and webhook only after HTTPS is healthy.
   The webhook endpoint is `https://YOUR_DOMAIN/telegram/webhook` because
   `bootstrap/app.php` sets an empty API prefix. Keep the existing
   `TELEGRAM_WEBHOOK_SECRET` as Bot API `secret_token`, and preserve
   `message`, `pre_checkout_query` and `subscription` in `allowed_updates`.
   In `getWebhookInfo`, the URL must match this route, `pending_update_count`
   should drain, and `last_error_message` must be empty. Send `/help` to
   verify that a reply with the Mini App button arrives. A legacy
   `/api/telegram/webhook` URL returns 404.
6. Test a separate Telegram account: Mini App identity, consent, trial,
   a RosTender monitoring, a repeat check, a new-card notification and the
   daily digest.
7. After releasing structured logging, open `/open` through the bot and
   confirm that the response has `X-Request-ID`, Laravel logged
   `http.request.completed`, and Caddy logged the path and matching request
   ID. Use the [mobile incident procedure](MOBILE-LOAD-INCIDENT-2026-10-19.md)
   if the Mini App hangs.
8. After releasing billing checks, run the no-payment alert delivery test
   above and confirm receipt in every configured administrator's private chat.
   Keep Stars disabled until the [Stars runbook](TELEGRAM-STARS-RUNBOOK.md)
   acceptance steps and legal decisions are complete.

## YooKassa boundary

`YOOKASSA_*` variables are intentionally only a placeholder. A future
implementation must create a separate external web checkout, fiscal receipt
configuration, refund workflow, provider signature validation, and legal
review. Do not offer YooKassa inside Telegram Mini Apps for digital service
access; Telegram requires Stars there.
