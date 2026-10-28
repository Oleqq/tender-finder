# Tender Finder: VPS deployment

> **28 октября 2026 — демонстрационный checkout:** реализован изолированный
> внешний путь `/demo`: 72-часовой триал, блокировка платных действий, серверный
> каталог 1/3/12 месяцев, заказ и атомарная выдача demo-подписки. Режим выключен
> по умолчанию; на VPS требует временную signed reviewer-сессию. Реальная
> ЮKassa выключена; `/pricing` и Mini App не открывают оплату. Правила, матрица
> доступа, приёмка и необходимые решения: [DEMO-CHECKOUT](DEMO-CHECKOUT.md).
> Ниже сохранена история предыдущих этапов. Наличие кода не подтверждает деплой;
> установленный commit проверяется через `.release-commit` после smoke-проверок.


## Выпуск 28 октября: «Мой прайс» для Pro / Ultra

В выпуск включён [каталог и сопоставление тендеров](PRICE-CATALOG-MATCHING.md)
из коммита `1a8b43f`. Вход — профиль → «Мой прайс», `/catalog`.
Миграция `2026_10_28_150000_create_price_catalogs` создаёт три личные таблицы:
`price_catalogs`, `price_catalog_items`, `price_catalog_comparisons`.
Штатный deploy запускает её перед пересозданием сервисов.

Новая зависимость OpenSpout 4.32.0 устанавливается из `composer.lock`.
`deploy/php-uploads.ini` копируется в образ: PHP принимает файлы до 10 МБ,
POST до 12 МБ, `upload_progress` выключен; Laravel ограничивает прайс 5 МБ.
Секреты и настройки оплаты для этой функции не требуются.

До выпуска пройдены 338 PHP-тестов / 3859 assertions, 11 JS-тестов, полный
`make check`, браузерный сценарий загрузки/правки/подбора/подтверждения,
16 сочетаний страниц, тем и ширин, реальные загрузки 3 и 6 МБ.
Перед установкой сверяются исходники предыдущего релиза с Git, проверяется
резервная копия PostgreSQL и сохраняется архив предыдущего кода.

Для VPS используется существующий проверенный PHP 8.3.36 base через
`TENDER_FINDER_USE_LOCAL_PHP_BASE=1`. После установки проверяются шесть
сервисов, HTTPS, маршрут `/catalog`, миграция и таблицы, лимиты загрузки,
наличие OpenSpout и доступ Pro/Ultra. Диагностика не создаёт каталоги
пользователям и не меняет выбранные подписки. Конфигурация окружения
сверяется по SHA-256; `.release-commit` указывает установленную ревизию.
При откате кода новые таблицы сохраняются, обратная миграция автоматически
не запускается.

## Выпуск 27 октября: публичная страница тарифов

Публичные `/pricing`, `/pricing/light` и `/pricing/dark` отдают автономные
HTML-страницы из `docs/` через `PricingPageController`. Ссылки «Тарифы и цены»
добавлены в профиль и «Мой доступ». Цены: Basic 349 ₽, Pro 599 ₽, Ultra 899 ₽
в месяц; периоды 3/12 месяцев и скидки описаны в [тарифах](SUBSCRIPTION-TARIFFS.md).
Новых миграций и настроек окружения нет, приём платежей остаётся закрыт.

В выпуск включены последние изменения `origin/main` до `1c930dd`, включая
жизненный цикл заявок и исправления интерфейса. Перед публикацией — `make check`,
JS-тесты, браузерная проверка цен и переходов, резервная копия БД и исходников.
После публикации — HTTPS-ответы обеих тем, заголовок HTML, содержимое цен,
новые ресурсы Profile/Plans, здоровье сервисов и совпадение `.release-commit`.
Основной домен — `https://tenderfinder.ru`; технический домен также сохраняется.

Проверки кандидата: 310 PHP-тестов / 3598 assertions, 11 JS-тестов,
Pint, PHPStan, TypeScript/Vite, ESLint и Prettier. Форматирование двух
выражений фильтров в `Tenders.tsx`, пришедших из `origin/main`, приведено
к Prettier. В Chromium проверены 36 сочетаний темы/срока/ширины, публичные
маршруты, смена темы, реквизиты, переходы из Profile/Plans и возврат в приложение.

## 26 октября: основной домен tenderfinder.ru

Основной адрес приложения — `https://tenderfinder.ru`. Делегирование на четыре
NS Timeweb завершилось; A-запись на `200.165.238.247` подтверждена в панели,
авторитетной зоне и публичных DNS Google/Cloudflare. После делегирования
оставшаяся ошибка `ERR_SSL_PROTOCOL_ERROR` была вызвана конфигурацией Caddy,
которая принимала только прежнее техническое имя.

В `.env.production` установлены `APP_URL=https://tenderfinder.ru`, новые
`LEGAL_OFFER_URL`/`LEGAL_PRIVACY_URL` и
`APP_DOMAIN="200.165.238.247.sslip.io, tenderfinder.ru"`. Пробел после запятой
обязателен для Caddy. Caddy получил сертификат Let's Encrypt и автоматически
обновляет его; платный сертификат из панели Timeweb для текущего HTTPS не
используется. Старый адрес продолжает обслуживаться для существующих ссылок
Telegram; настройки webhook и кнопки бота в этом изменении не заменялись.

Выполнено изменение конфигурации без замены исходников релиза `67a6faf`:
пересозданы Caddy, web, queue и scheduler. Предыдущий env сохранён с режимом
`600` в `/opt/tenderfinder/backups/domain-tenderfinder-20261026T071129Z`.
Проверены Chrome, валидный сертификат, `/`, `/health`, `/offer`, `/privacy`,
`/open`, ресурс Welcome и редирект HTTP → HTTPS. Все шесть сервисов работают.
Результат отправлен в тикет Timeweb `12906807`.

Двухдневное окно гостевого доступа завершилось 25 октября в 10:53:46 МСК;
при настройке домена срок не продлевался.

## Временный браузерный доступ для проверки ЮKassa

Окно включается только настройкой `TEMP_FULL_ACCESS_UNTIL` в
`/opt/tenderfinder/.env.production`. Значение — момент завершения в UTC,
строго в формате `YYYY-MM-DDTHH:MM:SSZ`; пустое или неверное значение
отключает окно. Для двух суток задайте время на 48 часов позже фактического
начала выпуска. Пример формата: `2026-10-25T10:00:00Z`.

Пока окно активно, все существующие и новые обычные пользователи, принявшие
актуальные оферту и политику, получают полный доступ и неограниченные
мониторинги. Пробный период в это время не расходуется. Роли `super_admin`
сохраняют прежние права. На главной странице появляется вход гостя из обычного
браузера. Он создаёт отдельный аккаунт без Telegram ID; браузер сохраняет
авторизацию в cookie. Данные разных браузеров не объединяются. Гость не
получает Telegram-уведомления и не имеет административных прав.

В заданный момент сервер закрывает гостевой вход, завершает гостевые сессии
при следующем запросе и возвращает обычные правила доступа. Планировщик
замораживает мониторинги пользователей без действующего доступа, а для
пользователей с действующим тарифом — только мониторинги сверх его лимита.
Сохранённые гостевые данные в базе не удаляются. Удаление строки из env и
перезапуск `web`, `queue`, `scheduler` закрывает окно досрочно. Изменение
env требует пересоздания этих контейнеров, чтобы обновился кеш конфигурации.

Перед включением: резервная копия PostgreSQL и проверка `gzip -t`, затем
миграция `2026_10_23_100000_add_browser_guest_to_users.php` штатным выпуском.
После выпуска: проверить `/health`, главную в обычном браузере, создание
гостя, два согласия, доступ к ленте и отсутствие доступа к `/operations`.
Не использовать аккаунты реальных покупателей для контрольных записей.

## Выпуск 23 октября: переключатель тарифов Developer Mode

В выпуск включён [переключатель Basic / Pro / Ultra](DEVELOPER-PLAN-SWITCHING.md)
в профиле. Миграция `2026_10_22_230000_add_developer_plan_code_to_users`
добавляет nullable-колонку `users.developer_plan_code`; прежние аккаунты
сохраняют исходный доступ. Настройки окружения не требуются. Штатный
`deploy/vps-deploy.sh` запускает миграцию перед пересозданием web/queue/scheduler.

До выпуска пройдены 291 PHP-тест / 3186 проверок, 10 JS-тестов, полный
`make check`. В браузере проверены 18 сочетаний тариф/тема/ширина,
6 экранов аналитики, сохранение выбора и сброс. Перед установкой создаются
проверенная копия PostgreSQL и архив кода предыдущего релиза.

После установки проверяются шесть сервисов, HTTPS `/health` и вход Mini App,
ресурсы Profile/аналитики, применённая миграция и серверное определение
тестового тарифа на объектах в памяти. Реальные подписки и выбранные планы
пользователей диагностикой не меняются. Релизный маркер и исходники
в контейнере должны совпасть с опубликованным коммитом GitHub `main`.

## Выпуск 22 октября: аналитика Basic / Pro / Ultra

В выпуск включено [разделение аналитики по тарифам](TENDER-ANALYTICS-TIERS.md).
Новых миграций и настроек окружения нет. До публикации пройдены 283 PHP-теста,
9 JS-тестов, PHPStan, Pint, TypeScript/Vite, ESLint и форматирование; 43
браузерные проверки покрывают три уровня, обе темы, ширины 320/390/1280 px,
калькулятор, историю и готовность заявки.

Используется тот же порядок: чистый коммит в GitHub, проверенная резервная
копия PostgreSQL, архив предыдущего кода и передача точного исходного
архива с SHA-256, затем `deploy/vps-deploy.sh`. После установки проверяются
сервисы, HTTPS, исходники внутри контейнера и новые ресурсы Vite.
Отдельная проверка тарифной логики на сервере выполняется на временных
объектах в памяти без выдачи подписок или изменения данных пользователей.
Релизный маркер `.release-commit` должен совпасть с опубликованным коммитом.

## Выпуск 22 октября: аналитика тендеров и выбор площадок

В состав выпуска входят [краткая аналитика и выбор площадок](TENDER-ANALYTICS-AND-SOURCES.md):
сводка в карточке и заявке, фильтр нескольких площадок в личной/командной
ленте и выбор источников мониторинга, включая опросы и уведомления.
Новых миграций и настроек окружения для этих функций нет.

Перед публикацией запускаются `DOCKER_CONTEXT=default make check` и
`npm run test:ui` в локальном Vite-контейнере. Архив формируется из коммита,
передаётся с проверкой SHA-256; перед заменой кода создаются проверенная
резервная копия PostgreSQL и архив предыдущей ревизии.

После стандартного `deploy/vps-deploy.sh` проверяются шесть сервисов,
HTTPS `/health`, вход Mini App и доступность новых JS/CSS из Vite manifest.
Контрольные суммы исходников и `.release-commit` должны совпасть с GitHub
`main`. Production `.env.production` сохраняется с проверкой SHA-256;
режим локального полного доступа на сервере остаётся выключен.

For the PHP critical security update of 21 October 2026, follow
[the incident runbook](PHP-SECURITY-2026-10-21.md) before the routine release
steps below.

> 22 октября: коммит `eb3b2a0` выпущен на VPS вручную из проверенного архива.
> Платёжный путь Stars удалён; клиент ЮKassa добавлен, но
> `YOOKASSA_ENABLED=false` и публичной оплаты пока нет. PHP обновлён до
> 8.3.36, `session.upload_progress.enabled=0` в `web`, `queue` и `scheduler`.
> Все шесть сервисов запущены, `/health` и `/open` отвечают `200`.
> Временная блокировка multipart снята после обновления PHP; тестовый POST
> на `/health` получает штатный `405` вместо прокси-ответа `503`.
> Резервная копия БД и предыдущий код сохранены перед выпуском.
> История прежних релизов ниже сохранена с датами.

> Проверка 20 октября: `e7bd19a` выпущен через workflow `39387852771`
> после свежей проверенной копии БД. Миграция `failed_jobs` применена,
> production `QUEUE_FAILED_DRIVER=database-uuids` загружен процессами Laravel.
> Все сервисы работают; `/health` и `/open?screen=plans` ответили `200`,
> контрольные хэши и `.release-commit` совпали с коммитом. Полный
> [отчёт maintenance](MAINTENANCE-2026-10-20.md).

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

The primary production address is `https://tenderfinder.ru` as of 26 October
2026. `https://200.165.238.247.sslip.io` remains an HTTPS alias for existing
Telegram links. The old VPS is retained unchanged and is not part of this
deployment target.

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

1. Completed on 26 October: `tenderfinder.ru` is the business domain and
   primary application URL. The former `200.165.238.247.sslip.io` alias is
   retained for existing Telegram entry points.
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
webhook secret, readiness token, and `APP_KEY` only in this file. The current
application does not read legacy `TELEGRAM_STARS_*` values. Keep the Telegram
webhook subscribed to `pre_checkout_query` during retirement so an old invoice
receives an explicit rejection rather than a timeout. Unexpected payment
updates are logged and retained for manual review; see the
[retirement note](TELEGRAM-STARS-RUNBOOK.md).

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
8. After releasing Stars retirement, verify authenticated `/plans` shows only
   access status and support, `/billing/invoice` returns 404, and `/subscribe`
   does not create an invoice. Confirm `schedule:list` has no Stars commands.
   Monitor `telegram.payment_update_received_after_retirement` for any old
   payment event. Do not send a real checkout query just for this smoke test.

## YooKassa boundary

`YOOKASSA_*` variables are intentionally only a placeholder. A future
implementation must create a separate external web checkout, fiscal receipt
configuration, refund workflow, provider signature validation, and legal
review. Do not offer YooKassa inside Telegram Mini Apps for digital service
access; Telegram requires Stars there.
