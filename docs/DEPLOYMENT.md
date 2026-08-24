# Tender Finder: развёртывание и безопасное переключение

## Коротко

Сейчас Vercel обслуживает безопасный demo Mini App и остаётся rollback-контуром.
Настоящие сессии Telegram, очередь, scheduler, trial и RSS требуют постоянного
runtime. Для него подготовлен один VPS с контейнерами `web`, `queue`,
`scheduler` и Caddy. PostgreSQL и Redis должны быть managed-сервисами вне VPS.

Ничего из этого документа не означает, что переключение уже выполнено. Для
production нужны доступ к VPS, домен, managed connection strings, публичные
legal URLs и Telegram secrets. Эти значения никогда не попадают в Git,
документацию или логи.

## Из чего состоит runtime

```text
Internet / Telegram
        │ HTTPS
      Caddy
        │
      web (Laravel) ─────────── managed PostgreSQL
        │  └ session/auth                 ▲
        │                                 │
      queue (Laravel worker) ─────── managed Redis
        │  └ bot / matching / delivery    ▲
        │                                 │
      scheduler (Laravel schedule:work) ──┘
```

Файлы для этого контура:

- `Dockerfile` — один собранный PHP+frontend image;
- `compose.production.yml` — процессы `web`, `queue`, `scheduler`, Caddy и
  отдельная operator-only migration job;
- `deploy/Caddyfile` — HTTPS reverse proxy;
- `deploy/entrypoint.sh` — кэш конфигурации/маршрутов без вывода secrets;
- `.env.example` — список имён переменных, не набор реальных значений.

## Что подготовить оператору

1. VPS с Docker Engine и Compose plugin, домен с A/AAAA записью на VPS и
   открытыми TCP 80/443.
2. Managed PostgreSQL 16: отдельный database/user, TLS по требованиям
   провайдера, backup и проверенный restore.
3. Managed Redis: TLS/пароль по требованиям провайдера, отдельные DB/namespace
   для session/cache/queue и доступ только с VPS.
4. Секретный store VPS (или защищённый некоммитируемый `.env.production`) с
   `APP_KEY`, DB/Redis, Telegram token/webhook secret/owner ID, legal URLs и
   versions, `OPERATIONS_READINESS_TOKEN`.
5. Значения runtime: `APP_ENV=production`, `APP_DEBUG=false`,
   `SESSION_SECURE_COOKIE=true`, `SESSION_DRIVER=redis`, `CACHE_STORE=redis`,
   `QUEUE_CONNECTION=redis`, `DB_CONNECTION=pgsql` и `APP_DOMAIN` для Caddy.

Не запускайте `.env.example` как production-файл: он специально пустой в
чувствительных местах и содержит локальные примеры.

## Первый запуск без переключения Telegram

На VPS в checkout репозитория оператор выполняет следующие шаги. Команды не
содержат secrets; перед ними они уже должны быть в secret store.

```sh
docker compose -f compose.production.yml build
docker compose -f compose.production.yml --profile ops run --rm migrate
docker compose -f compose.production.yml up -d web queue scheduler caddy
```

Затем обязательно проверить:

1. Public `GET /health` возвращает только `status: ok` — это liveness и он
   намеренно не раскрывает доступность PostgreSQL/Redis.
2. Operator делает `GET /ops/readiness` с Bearer readiness token. Успех
   означает, что Laravel смог выполнить `select 1` в PostgreSQL и `PING` Redis;
   при ошибке виден только `not_ready`, без строки подключения.
3. `docker compose ... ps`, logs web/queue/scheduler, Laravel migrations и
   HTTPS certificate Caddy выглядят штатно.
4. На отдельном тестовом Telegram-пользователе проверяются signed Mini App
   session, consent, ровно один trial, `/start` и `/help`. Ни цены Stars, ни
   live RSS на этом шаге не включаются.

## Controlled cutover и rollback

После успешного smoke-test:

1. Установить VPS HTTPS URL как URL Mini App/menu button.
2. Установить Telegram webhook на VPS с секретным token header.
3. Наблюдать health, readiness, queue failures, webhook updates и source runs.
4. Только после SRC-00 включить `RSS_LIVE_POLLING_ENABLED=true`; прежде этого
   работают только synthetic fixtures.

Если smoke-test или наблюдение не проходят, вернуть menu button/webhook на
Vercel demo URL. Не откатывать миграции на живых данных автоматически: сначала
остановить пользовательские mutations, взять backup и определить обратимую
процедуру для конкретной migration.

## Что Vercel делает дальше

Vercel не удаляется и не превращается в real worker: там остаётся текущий
public demo/rollback. Его serverless filesystem и короткие процессы не подходят
для очередей и scheduler. После устойчивого VPS периода Vercel можно оставить
как static rollback или закрыть отдельным инфраструктурным решением.

## Проверки при каждом обновлении

До push выполняются локально:

```powershell
npm run build
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run lint
npm run format:check
```

Перед migration в production: backup, operator readiness, deployment smoke-test
и план rollback. Подробная таблица и порядок migration: [DATABASE.md](DATABASE.md).
