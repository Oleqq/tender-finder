# Tender Finder: технический план разработки

## 1. Архитектурное решение

Один репозиторий и один Laravel 12 application. React + TypeScript + Vite +
Inertia реализуют SPA-навигацию Mini App без React Router. Laravel владеет
данными, Telegram, проверкой `initData`, сессиями, webhook, очередями,
платежами и Inertia endpoints. Отдельные backend- или frontend-репозитории не
создаются до подтверждённой нагрузки.

- PHP 8.3+, Laravel 12;
- React, TypeScript, Vite и Inertia;
- PostgreSQL 16 — данные, JSONB-фильтры и поиск;
- Redis — sessions, cache, locks, queues и rate limits;
- Laravel Scheduler / Horizon — jobs и наблюдение;
- Telegram Mini Apps + Bot API;
- Telegram Stars (`XTR`) — первая оплата цифрового доступа внутри Telegram;
- HTTPS production-контур; Vercel — только текущий demo/foundation до
  подключения постоянной базы и Redis.

```text
Telegram Mini App → React/Inertia → Laravel → PostgreSQL
Telegram Bot      → Laravel webhook ↘ Redis queues → imports / delivery / campaigns
Telegram Stars    → Bot API events → Laravel → entitlements / audit
Super-admin UI    → те же Inertia endpoints → server-side policies
```

## 2. Модули

| Модуль | Ответственность | Приоритет |
|---|---|---|
| Design system | tokens, primitives, states, Telegram UX, mobile performance | Сейчас |
| Client Mini App | preview, onboarding, tenders, queries, billing, profile | MVP |
| Identity & consent | `initData`, session, consent versions, role bootstrap | MVP |
| Access | trial, plan, entitlement, feature limits | MVP |
| Tender core | queries, RSS adapter, catalogue, deduplication, rules matching | MVP |
| Notifications | transactional delivery, anti-spam, preferences | MVP |
| Commerce | Stars invoices, checkout events, refunds, billing audit | MVP |
| Embedded admin | overview, users, campaigns, sources, ops, audit | После core-данных |
| LLM scoring | evaluation, personal ranking, ToR analysis | Future PRO |

## 3. Авторизация, роли и доступ

### Роли

Единственный enum ролей после migration: `subscriber`, `super_admin`. План,
trial и access state не кодируются как роль. Переход с текущего временного
`user`/`admin` enum выполняется одной миграцией вместе с введением Telegram
identity — до него не делать частичную смену enum.

Bootstrap super-admin хранится в защищённой production-конфигурации как один
Telegram ID. После серверной проверки `initData` domain service назначает
`super_admin` пользователю с совпадающим ID; всех остальных создаёт как
`subscriber`. Нельзя принимать Telegram ID, роль или entitlement из React.

Политики и middleware проверяют super-admin на каждом admin read и mutation.
Видимость navigation — UX, не средство авторизации. Доступ владельца в обычный
продукт выдаётся отдельным audited `access_grant`/entitlement, не поддельной
оплатой и не исключением в paywall-коде.

### Данные

| Таблица | Назначение |
|---|---|
| `users` | Telegram identity, profile-safe fields, `role`, `last_seen_at` |
| `consents` | документ, версия, тип, время принятия/отзыва |
| `plans` | code, `XTR` price, period, limits, feature flags |
| `subscriptions` | user, plan, state, period, source of entitlement |
| `entitlements` | конкретное право, limit/value, valid interval, source |
| `payments` | provider `telegram_stars`, invoice, payment charge ID, amount, status, idempotency key |
| `billing_events` | входящее Telegram-событие, safe payload digest, processing result |
| `search_queries` | user, state, JSONB filters and limits |
| `tenders` / `tender_query_matches` | canonical tender and explainable match reasons |
| `notification_deliveries` | type, idempotency key, delivery lifecycle |
| `campaigns` / `campaign_deliveries` | segment, template, consent basis, schedule, outcomes |
| `source_runs` / `system_metrics` | source freshness, queue/system aggregates |
| `admin_audit_logs` | actor, action, object, safe summary, correlation, timestamp |

Индексы: `users.telegram_id`, `(source, external_id)` tender,
`(user_id, tender_id, type)` delivery, unique external payment/charge ID и
idempotency keys. Sensitive payloads не дублируются в audit/log tables.

### Статусы

- subscription/access: `preview`, `trialing`, `active`, `expired`,
  `cancelled`;
- payment: `created`, `pre_checkout_approved`, `succeeded`, `failed`,
  `refunded`;
- query: `active`, `paused`, `frozen`, `deleted`;
- campaign: `draft`, `scheduled`, `running`, `completed`, `cancelled`.

Статусы — PHP enums, transitions — domain services. React получает DTO и не
меняет state напрямую.

## 4. Telegram и Stars

1. WebApp client передаёт `initData`; Laravel проверяет HMAC и freshness,
   находит/создаёт пользователя, пишет `last_seen_at`, назначает роль и создаёт
   безопасную сессию.
2. `/start`, `/help` и menu button ведут в Mini App. Webhook валидирует secret
   token, дедуплицирует update ID и отправляет работу в очередь.
3. При click на plan Laravel создаёт invoice с серверно определённой ценой,
   React открывает его через Telegram WebApp API.
4. Laravel отвечает на `pre_checkout_query` в срок Telegram, обрабатывает
   `successful_payment` идемпотентно и только затем активирует entitlement.
5. Возврат использует сохранённый Telegram payment charge ID, меняет access
   по явной business rule и создаёт billing/audit event.

До появления PostgreSQL/Redis и юридических URLs остаётся только demo Mini App:
никаких попыток подменять persistence или включать оплату в serverless-array
режиме.

### Demo commerce process

До подключения server-side commerce UI может показывать только подписанные
`demo` состояния: access `preview`/`trialing`/`active`/`expired` и checkout
preview/loading/retry/active-example. Они существуют лишь в памяти React,
не создают Telegram Stars invoice, не запускают trial, не меняют plan или
entitlement и не отправляют запросов, способных повторно выдать доступ.

Настоящий checkout заменит этот слой только после server-side проверки Telegram
`initData`: Laravel определяет цену и лимиты, создаёт invoice, а доступ меняется
лишь после идемпотентно обработанного подтверждения платежа. UI error/retry
переиспользуется, но его причина и последующее состояние поступают через API.

## 5. Очередность реализации

### A. UI foundation и preview — сейчас

- снизить blur/gradient density текущих demo-экранов;
- закрыть library primitives и states из `DESIGN-SYSTEM.md`;
- создать preview, plan comparison, access gate, payment loading/error
  patterns; все значения маркировать как demo;
- добавить admin shell и read-only demo Overview/Live Ops без ложных
  telemetry claims;
- feature tests маршрутов, визуальная проверка mobile viewport и reduced
  motion.

### B. Identity, consents, trial — кодовая foundation готова, production ждёт readiness

- migrations пользователей, consents, role/access domain — реализованы;
- server validation `initData`, secure session, `/start`, `/help`, webhook —
  реализованы и покрыты forged/expired/duplicate tests;
- consent flow и one-time 72h trial — реализованы; reminders 24h/3h остаются
  отдельной задачей после production activation;
- activation gate: managed PostgreSQL/Redis, public legal URLs, VPS HTTPS
  smoke-test и настройка Telegram secrets вне Git.

### C. Basic tender core — fixtures и domain готовы, live source выключен

- authenticated query API/UI: keywords, minus words, region, budget, deadline,
  pause/freeze и server-side limit 3 active queries;
- `TenderSource`, `EisRssSource`, canonical URL validation, timeout/size/XML
  errors and deduplication; synthetic fixtures prove first-poll silence;
- explainable deterministic matching and notification delivery ledger with
  20 cards/hour → top-10 digest rule;
- live external fetch is guarded by `RSS_LIVE_POLLING_ENABLED=false` until
  SRC-00 confirms EIS RSS URLs, allowed redirect behavior and terms.

### D. Commerce and Basic activation

- plan/entitlement services and paywall API;
- Telegram Stars invoice, pre-checkout, successful payment and refund;
- subscription lifecycle, frozen queries and billing notifications;
- tests: success, failure, timeout, duplicate payment update, refund,
  entitlement idempotency.

### E. Embedded super-admin

- policies, audit events and role-aware navigation;
- business overview, users, commerce, sources and system health read models;
- campaign composer: segment, preview, dry-run, schedule, quiet hours,
  rate limits and delivery ledger;
- telemetry definition for each metric, not client-side guessed values.

### F. Future PRO intelligence

- collect opt-in feedback and an evaluation dataset;
- build explainable rules-based ranking improvements first;
- define measurable quality/cost/privacy gate;
- only then implement LLM scoring and ToR analysis behind entitlement and
  observability.

## 6. Admin information architecture

| Screen | Read model / action |
|---|---|
| Overview | acquisition, trial, paid conversion, revenue/refunds, campaign funnel |
| Live Ops | recent `last_seen`, jobs, heartbeat, source age, webhook errors, latency |
| Users | safe profile, consent, access, payments, queries, notification timeline |
| Commerce | invoices, refunds, manual grants with reason/expiry |
| Campaigns | draft, preview, segment count, send state, failures, cancellation |
| Sources | health, source run details, lag, errors, new tenders |
| Audit | immutable action/event timeline with redacted context |

Every screen has empty/loading/error/access-denied states. Aggregates use
server-side time windows and definitions. No separate public admin domain or
browser password flow is planned; Mini App session plus `super_admin` policy is
the intended owner experience.

## 7. Mandatory verification

- unit: status transitions, plan limits, matching reasons, Stars money values;
- integration: `initData`, webhook secret, duplicate updates, payment and
  refund idempotency, server policies, campaign limits;
- feature: onboarding → trial → query → match → paywall → entitlement;
- feature: super-admin data access, audit event, denied subscriber request;
- operational: queue failure/retry, RSS failure, backup/restore and metric
  aggregation;
- frontend: mobile navigation, skeleton/empty/error/disabled states, keyboard,
  touch targets, Telegram fallback and reduced motion.

Before every push: `php artisan test`, `vendor/bin/pint --test`,
`vendor/bin/phpstan analyse --memory-limit=1G`, `npm run lint`,
`npm run format:check`, `npm run build`.

## 8. External decisions before backend blocks

1. Managed PostgreSQL and Redis for production and durable jobs/sessions.
2. Published offer and privacy policy versions/URLs.
3. Basic/PRO limits and prices in Stars; subscription-period business model.
4. Verified Telegram test account and payment/refund test cases.
5. RSS test corpus and EIS data-source terms.

Component ownership and motion rules: [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md).
Business backlog and campaign model: [PRODUCT-ROADMAP.md](PRODUCT-ROADMAP.md).
Data model, simple explanation and migration runbook: [DATABASE.md](DATABASE.md).
