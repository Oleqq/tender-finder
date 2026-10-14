# RosTender API integration

> The production feed is configured, but cannot currently import new cards:
> on 14 October 2026 the authenticated, quota-free `info` endpoint reported
> `daily_limit=0`, `used_today=0`, `remaining=0`. See the incident below.

## Production diagnosis, 14 October 2026

The VPS has a configured API key and one active saved-template feed (ID
`2305550`). The last successful poll was 16 September. The latest poll failed
with `remote_quota_or_access_denied`. A read-only `GET /api/tenders/get/info`
from the production network returned HTTP 200 and `success=true`, so the key
was accepted. Its reported `daily_limit=0`, `used_today=0`, and `remaining=0`
explain why a data request is denied. This is an account/tariff entitlement
problem, not a broken SSH connection or an exhausted 200-call allowance.

The local `ROSTENDER_DAILY_QUOTA_LIMIT=200` is only our safety setting. It
does **not** grant 200 requests at RosTender. Likewise, a local
`rostender_api_usages.successful_requests=200` on a day with a remote 403 is
not evidence of 200 successful calls: the guard reconciles a remote
`X-RateLimit-Remaining: 0` against its configured 200-call limit. Read the
supplier's `info` response or cabinet journal for actual entitlement and use.

Next steps for the account owner:

1. In the RosTender cabinet, check **Profile → Integrations → API** for the
   active tariff/add-on, daily limit, and request journal. Ask RosTender
   support to activate or renew Client API access if the limit remains zero.
2. Confirm in writing that Tender Finder may display/import the data for its
   users, export it, and send Telegram notifications under that tariff. Keep
   the confirmation with the project agreement; do not share the login,
   password, or full API key in chat.
3. Once `info` reports a positive limit, set our daily limit no higher than
   the real allowance, keep a reserve, and perform one controlled poll of
   template `2305550`. Verify a successful source run, imported detail cards,
   rate-limit headers, and the next scheduled poll before treating the source
   as live.

Official contract: [RosTender Client API](https://rostender.info/docs/api).

## Legal and operational boundary

RosTender was initially implemented as a dormant server-side source. It must
not fetch, store for distribution, display, export, or notify about RosTender
data unless the supplier has supplied written permission for Tender Finder's
intended user distribution. Both switches must be true before any queue job
can call the API:

```dotenv
ROSTENDER_ENABLED=true
ROSTENDER_PUBLIC_DISTRIBUTION_APPROVED=true
```

In a new environment, keep both `false` until that approval and the applicable
licence/tariff have been reviewed. A key is a production secret: store
`ROSTENDER_API_KEY` only in `/opt/tenderfinder/.env.production` (mode `600`),
never in Git, tickets, commands, screenshots, or logs. If a key appears in a
conversation or other untrusted channel, revoke and replace it in the
RosTender cabinet before use.

The original adapter change did not activate production; later VPS releases
did. See [CURRENT-STATE](CURRENT-STATE.md) for the current release status.

## API contract used

The adapter follows the official Client API documentation:

- Base URL: `https://rostender.info/api/tenders/get`.
- All calls are `GET` and carry `X-API-KEY` server side.
- `template/{id}?page=1&sort=new-first` obtains a saved-template list, up to
  100 items per page.
- `{id}` obtains a detail card. `updated_at`, `files`, EIS number and other
  card data are retained with the tender.
- A successful request consumes quota; `info` is not used by background work.

The implementation intentionally performs only page 1 for a shared saved
template. It does not create arbitrary user search requests, scrape HTML, use
proxies, weaken TLS, or poll on page loads/text input.

The personal feed's phrase action creates a user monitoring attached to the
single active saved template. It matches already imported, still-open cards
immediately and dispatches a due poll only when normal quota is available.
Typing a phrase never calls a free-text RosTender endpoint. If multiple
templates are active, the user must choose one in the monitoring wizard.
During quota exhaustion that wizard can reuse an already connected template
without another API call. Detail cards fetched before a mid-batch quota
failure are persisted and matched, while the source run still records the
failure and cooldown.

## Shared-template and quota model

`source_feeds` now supports `source=rostender` and a `source_identifier`
(RosTender template ID). `rostender_feed_search_query` links one shared feed to
the user monitorings that use it. The queued poll fetches the list once,
fetches detail cards only for tender IDs whose global `details_fetched_at` is
empty, then matches only the linked monitorings. The first completed poll is
silent, preventing historical-list notification bursts.

`rostender_api_usages` keeps Moscow-day successful and in-flight counters.
Normal scheduled calls can use at most:

`ROSTENDER_DAILY_QUOTA_LIMIT - ROSTENDER_DAILY_QUOTA_RESERVE`

The default is 200 minus a 20-call operational reserve. A reservation is made
before a request and converted to a successful call only for a 2xx response;
failed connections and errors release it. Reaching the guard records a failed
source run with `quota_exhausted` without sending another HTTP request. The
next normal poll is scheduled no earlier than 00:05 on the next Moscow day and
no earlier than the configured poll interval. A remote 403
(`remote_quota_or_access_denied`) uses the same cooldown; it is not proof that
the local quota counter is full. The scheduler does not queue the cooled-down
feed again before that time. `X-RateLimit-Remaining`, when returned, also
reconciles the local counter with calls made outside this application.

During local quota exhaustion or an active remote 403 cooldown, a manual check
is rejected before its per-user allowance is spent and before a job is queued.
The monitoring card shows the last failure and next attempt; its manual button
is disabled during the recorded cooldown. The server repeats the guard check,
including when the UI has stale data. The operational reserve remains
unavailable to normal and manual checks.

`ROSTENDER_MAX_DETAILS_PER_POLL` bounds detail-card fan-out. Configure the
daily interval and limits only after checking the actual contract and usage:

```dotenv
ROSTENDER_DAILY_QUOTA_LIMIT=200
ROSTENDER_DAILY_QUOTA_RESERVE=20
ROSTENDER_BASIC_POLL_INTERVAL_SECONDS=3600
ROSTENDER_PRO_POLL_INTERVAL_SECONDS=3600
ROSTENDER_MAX_DETAILS_PER_POLL=20
ROSTENDER_BASIC_MANUAL_CHECKS_PER_DAY=0
ROSTENDER_PRO_MANUAL_CHECKS_PER_DAY=0
ROSTENDER_BASIC_ACTIVE_MONITOR_LIMIT=0
ROSTENDER_PRO_ACTIVE_MONITOR_LIMIT=0
```

The monitoring attachment service applies the Basic/Pro active-source and
refresh-frequency limits; the manual-check service applies the matching
Moscow-day per-user plan cap. Production exposes the approved monitoring and
manual-check flow. Preserve a zero limit in a new environment until its
product policy is approved.

The quota UX was inspected separately on mobile and desktop.

## Safe activation checklist

1. Obtain written confirmation that the intended authenticated-user display,
   export, and Telegram notification use is licensed.
2. Rotate any exposed API key; put only the replacement into the protected VPS
   environment file.
3. Set the limits and refresh cadence from the purchased API allowance, leaving
   headroom for detail cards and operations.
4. Enable both legal switches and select one saved RosTender template when
   creating a Tender Finder monitoring. The API does not expose arbitrary
   free-text search or template creation; it polls the template selected in
   the RosTender cabinet and Tender Finder applies the monitoring criteria to
   the imported cards.
5. Run one controlled queue smoke test, verify rate-limit headers/counters,
   initial-import silence, scoped matching, and no unexpected detail refetch.
6. Only then expose a reviewed monitoring-management UI and update legal
   documentation/product copy.
