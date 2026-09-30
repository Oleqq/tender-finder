# Tender Finder: VPS deployment

## Current status

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

The VPS is currently updated by a reviewed manual release. The repository also
contains a GitHub Actions workflow named **Deploy to VPS**, but it is triggered
only manually (`workflow_dispatch`) until the four repository secrets below
are configured. This prevents a push with an unreviewed or incomplete server
configuration from changing production.

Once the secrets exist, a maintainer starts **Deploy to VPS** for a green
commit on `main`. Actions transfers that exact revision over SSH and calls
`/opt/tenderfinder/deploy/vps-deploy.sh`. The VPS has no credential for the
private GitHub repository. The script builds containers, runs forward-only
migrations, starts the services, and removes unused image layers. Shell scripts
are normalized to LF by `.gitattributes` so the deployment script is portable
between Windows worktrees and the Linux VPS.

## Latest verified release

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
the Mac still occur. Visual acceptance inside Telegram and a real first
RosTender search remain closed beta checks. UI details are in
[FIRST-RUN-UX-QA](FIRST-RUN-UX-QA.md).

On 30 September 2026, commit `57017c7` delivered the RosTender quota
cooldown. Both repositories' CI passed. Backup
`postgres-20260930T084130Z.sql.gz` passed `gzip -t`; the tracked source
archive checksum matched on the VPS, and `.env.production` retained mode
`600`. The standard `deploy/vps-deploy.sh` stopped during its `--pull` build
because Docker Hub returned HTTP 429. No running service had been replaced.
Official `linux/amd64` base images were downloaded on the Mac, transferred
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
SSH/HTTPS connections from this Mac intermittently stall before authentication
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
returned `ok`. The first external request from the new Mac timed out before a
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

During this release, some new SSH and HTTPS connections from the new Mac timed
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

Copy the remaining variables from `.env.example`. Store the Telegram token,
webhook secret, readiness token, and `APP_KEY` only in this file. Keep Stars
disabled until the approved XTR amounts are populated:

```dotenv
TELEGRAM_STARS_ENABLED=false
TELEGRAM_STARS_BASIC_PRICE_XTR=0
TELEGRAM_STARS_PRO_PRICE_XTR=0
```

## Release checks

1. Before every release, GitHub CI must be green: frontend build, PHP tests,
   PHPStan, Pint, ESLint and whitespace check.
2. Point the final domain's A record to the VPS and wait for DNS propagation.
3. Start the stack; Caddy obtains the TLS certificate after ports 80/443 are
   reachable from the Internet.
4. Check `https://YOUR_DOMAIN/health` and the private readiness endpoint.
5. Configure the bot's menu button and webhook only after HTTPS is healthy.
   The webhook endpoint is `https://YOUR_DOMAIN/api/telegram/webhook`.
6. Test a separate Telegram account: Mini App identity, consent, trial,
   a RosTender monitoring, a repeat check, a new-card notification and the
   daily digest.

## YooKassa boundary

`YOOKASSA_*` variables are intentionally only a placeholder. A future
implementation must create a separate external web checkout, fiscal receipt
configuration, refund workflow, provider signature validation, and legal
review. Do not offer YooKassa inside Telegram Mini Apps for digital service
access; Telegram requires Stars there.
