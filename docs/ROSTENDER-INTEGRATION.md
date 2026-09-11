# RosTender API integration

## Legal and operational boundary

RosTender is implemented as a dormant, server-side source. It must not fetch,
store for distribution, display, export, or notify about RosTender data unless
the supplier has supplied written permission for Tender Finder's intended user
distribution. Both switches must be true before any queue job can call the API:

```dotenv
ROSTENDER_ENABLED=true
ROSTENDER_PUBLIC_DISTRIBUTION_APPROVED=true
```

Keep both `false` until that approval and the applicable licence/tariff have
been reviewed. A key is a production secret: store `ROSTENDER_API_KEY` only in
`/opt/tenderfinder/.env.production` (mode `600`), never in Git, tickets,
commands, screenshots, or logs. If a key appears in a conversation or other
untrusted channel, revoke and replace it in the RosTender cabinet before use.

No production deployment or activation is part of this change.

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
source run with `quota_exhausted` and leaves the monitoring for the next
scheduled run. `X-RateLimit-Remaining`, when returned, also reconciles the
local counter with calls made outside this application.

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
Moscow-day per-user plan cap. Neither is
exposed as a public UI action in this dormant release. Preserve a zero limit
until the product policy is approved.

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
