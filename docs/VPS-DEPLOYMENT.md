# Tender Finder: VPS deployment

## Current status

The production stack is one Docker Compose application: Caddy manages HTTPS;
Laravel `web`, `queue`, and `scheduler` run separately; PostgreSQL and Redis
are private Docker services with persistent volumes. A systemd timer creates a
compressed PostgreSQL backup every day at 03:15 UTC and removes backups older
than 30 days.

The technical production address is `https://200.169.176.78.sslip.io`. It is
not a business domain and should be replaced before public promotion.

The VPS is currently updated by a reviewed manual release. The repository also
contains a GitHub Actions workflow named **Deploy to VPS**, but it is triggered
only manually (`workflow_dispatch`) until the four repository secrets below
are configured. This prevents a push with an unreviewed or incomplete server
configuration from changing production.

Once the secrets exist, a maintainer starts **Deploy to VPS** for a green
commit on `main`. Actions transfers that exact revision over SSH and calls
`/opt/tenderfinder/deploy/vps-deploy.sh`. The VPS has no credential for the
private GitHub repository. The script builds containers, runs forward-only
migrations, starts the services, and removes unused image layers.

## One-time owner inputs

These items cannot be safely guessed or created by deployment code:

1. A domain controlled by the business. `200.169.176.78.sslip.io` can be used
   for a temporary technical check only; it is not a brand domain.
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
   monitoring, first RSS search, a repeat check, a new-card notification and
   the daily digest. The EIS feed part must wait until outbound VPS access to
   `zakupki.gov.ru:443` is restored.

## YooKassa boundary

`YOOKASSA_*` variables are intentionally only a placeholder. A future
implementation must create a separate external web checkout, fiscal receipt
configuration, refund workflow, provider signature validation, and legal
review. Do not offer YooKassa inside Telegram Mini Apps for digital service
access; Telegram requires Stars there.
