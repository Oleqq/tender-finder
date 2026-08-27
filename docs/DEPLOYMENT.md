# Tender Finder: Railway deployment (pre-MVP)

## Итоговая схема

Railway разворачивает не Docker Compose «одной кнопкой», а несколько постоянных
сервисов из одного репозитория. Это полностью покрывает нужный pre-MVP контур:

```text
Telegram Mini App / webhook
              │ HTTPS
              ▼
      Railway: tender-web (public)
         │        │
         │        ├──────── Railway PostgreSQL
         │        └──────── Railway Redis
         │
         ├── Railway: tender-worker (private, queue:work)
         └── Railway: tender-scheduler (private, schedule:work)
```

`web` обслуживает браузер и Telegram webhook. `worker` обрабатывает Redis
очередь, а `scheduler` запускает lifecycle-задачи Laravel каждую минуту.
PostgreSQL и Redis — managed database services Railway; у приложения нет
Docker volume с пользовательскими данными.

Обычный Railway service постоянный по умолчанию. Не включайте **Serverless**
для `web`, `worker` или `scheduler`: это режим сна, не production-оптимизация.
После завершения trial/перехода на оплачиваемый план задайте для worker и
scheduler Restart Policy **Always**. На trial Railway ограничивает эту
политику, поэтому «работает 24/7» нельзя гарантировать при исчерпании trial
кредитов или лимитов аккаунта.

## Что уже подготовлено в репозитории

- `Dockerfile` — один production image PHP + Laravel + Vite assets; web
  использует Railway-переменную `$PORT`.
- `railway.json` — Railway всегда собирает этот image через Dockerfile, но не
  задаёт общий start command.
- `railway/run-web.sh` — HTTP web process.
- `railway/run-worker.sh` — Redis worker.
- `railway/run-scheduler.sh` — постоянный Laravel scheduler.
- `railway/migrate.sh` — безопасная команда migration только для web service.

`compose.local.yml` остаётся исключительно локальным контуром и не участвует
в Railway production deployment.

## Создание Railway проекта

После регистрации создать пустой проект, затем на Canvas добавить:

1. **PostgreSQL**. Оставить private networking; сервис создаёт
   `DATABASE_URL`.
2. **Redis**. Оставить private networking; сервис создаёт `REDIS_URL`.
3. Три сервиса из одного GitHub-репозитория и ветки `main`:
   `tender-web`, `tender-worker`, `tender-scheduler`.

Для всех трёх сервисов Railway сам найдёт `railway.json` и `Dockerfile`.
Публичный домен создаётся только у `tender-web`. Не выдавайте домены database,
Redis, worker и scheduler.

## Команды сервисов

Задать их в **Service → Settings → Deploy**:

| Service | Custom Start Command | Healthcheck | Pre-Deploy Command |
|---|---|---|---|
| `tender-web` | `sh railway/run-web.sh` | `/health` | `sh railway/migrate.sh` |
| `tender-worker` | `sh railway/run-worker.sh` | не задавать | не задавать |
| `tender-scheduler` | `sh railway/run-scheduler.sh` | не задавать | не задавать |

Сначала развернуть PostgreSQL и Redis, затем `tender-web` (он выполнит
migrations), после успешного healthcheck — worker и scheduler. Каждый из
worker/scheduler запускается как отдельный постоянный контейнер; не пытайтесь
запустить их фоном внутри web service.

## Variables

У каждой из трёх application-служб должны быть одинаковые runtime variables.
Секреты добавляются через Railway Variables и не коммитятся в Git.

### Reference variables

Если database services названы именно `Postgres` и `Redis`, добавить:

```text
DB_CONNECTION=pgsql
DB_URL=${{Postgres.DATABASE_URL}}
REDIS_URL=${{Redis.REDIS_URL}}
REDIS_CLIENT=phpredis
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
SESSION_SECURE_COOKIE=true
```

Если в Canvas выбраны другие имена, Railway подставляет их в reference
variables: `${{<имя-сервиса>.DATABASE_URL}}` и
`${{<имя-сервиса>.REDIS_URL}}`. Не используйте public DB URL между сервисами.

### Application variables

Во все три application-службы добавить одинаковые значения:

```text
APP_ENV=production
APP_DEBUG=false
APP_KEY=<сгенерировать один раз; не менять между сервисами>
APP_URL=https://<домен-tender-web>
LOG_CHANNEL=stderr
LOG_LEVEL=info
TELEGRAM_BOT_TOKEN=<secret>
TELEGRAM_WEBHOOK_SECRET=<secret>
TELEGRAM_SUPERADMIN_IDS=<личный Telegram user ID владельца,ID второго администратора>
TELEGRAM_OWNER_ID=<числовой Telegram ID владельца>
OPERATIONS_READINESS_TOKEN=<случайный secret>
LEGAL_DOCUMENTS_PUBLISHED=false
RSS_LIVE_POLLING_ENABLED=false
```

`TELEGRAM_SUPERADMIN_IDS` — перечень личных Telegram **user ID** через
запятую. Его сравнивают только с криптографически проверенным `initData` Mini
App; ID группы/канала сюда не подходит. `TELEGRAM_OWNER_ID` оставлен как
совместимый alias одиночного владельца. При следующем входе ID, удалённый из
обоих значений, автоматически станет `subscriber`.

Остальные имена сверять с `.env.example`. Не переносите локальный
`deploy/local-runtime.env`, `.env` или Redis dump в Railway. Перед выпуском
legal-документов нельзя включать `LEGAL_DOCUMENTS_PUBLISHED=true`; trial
останется безопасно заблокированным.

## Первый запуск и проверки

1. В `tender-web` сгенерировать Railway domain и записать его HTTPS URL в
   `APP_URL`, затем redeploy web.
2. Убедиться, что `/health` возвращает `{"status":"ok"}`.
3. С приватным `OPERATIONS_READINESS_TOKEN` вызвать
   `GET /ops/readiness` с `Authorization: Bearer …`; ответ `ready` означает,
   что приложение подключилось и к PostgreSQL, и к Redis.
4. Просмотреть логи всех трёх сервисов. Worker должен оставаться в
   `queue:work`, scheduler — в `schedule:work`; у них не должно быть restarts.
5. На тестовом Telegram-аккаунте проверить подписанную Mini App session,
   consent, trial и ручной поиск ЕИС. `RSS_LIVE_POLLING_ENABLED` оставлять
   `false` до отдельного решения владельца.
6. Только затем настроить Telegram Mini App URL и webhook на Railway HTTPS
   domain с заданным webhook secret.

## Контролируемая удалённая проверка MVP

До подключения Telegram можно временно открыть рабочее место ЕИС на Railway,
не публикуя debug-вход и не подменяя Telegram ID:

1. Только у web-сервиса (сейчас это `tender-finder`) задать
   `REMOTE_MVP_OPERATOR_ENABLED=true` и дождаться нового deployment.
2. Открыть **Console** именно web-сервиса и выполнить:

   ```sh
   php artisan mvp:operator-link --minutes=30
   ```

3. Открыть выведенную ссылку в своём браузере. Она выдаёт техническую
   `super_admin`-сессию только на рабочее место ЕИС и истекает максимум через
   60 минут. Не пересылайте её и не добавляйте в закладки.
4. Закончив приёмку, поставить
   `REMOTE_MVP_OPERATOR_ENABLED=false` и redeploy web-сервис.

Это не публичная авторизация и не заменяет Telegram Mini App. Обычный
пользователь после запуска будет входить через Telegram; роль ему назначит
`TELEGRAM_SUPERADMIN_IDS` либо обычный subscriber-flow.

## Обновление и rollback

Каждый push в `main` создаёт новый deployment каждого подключённого Railway
service. Перед изменением схемы: сделать PostgreSQL backup, deploy web с
migration, проверить health/readiness, затем worker и scheduler. При ошибке
не откатывать migrations автоматически: сначала остановить mutations, сделать
backup и оценить обратимость конкретной migration. Кодовой rollback выполняется
на предыдущий успешный Railway deployment.

## Локальные проверки перед push

```powershell
npm run build
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run lint
npm run format:check
docker build -t tender-finder:railway .
```

Официальные материалы: [Laravel on Railway](https://docs.railway.com/guides/laravel),
[Dockerfiles](https://docs.railway.com/builds/dockerfiles),
[restart policy](https://docs.railway.com/deployments/restart-policy).
