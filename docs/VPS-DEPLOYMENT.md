# VPS: эксплуатация и выпуск

Основной адрес — `https://tenderfinder.ru`, каталог — `/opt/tenderfinder`.
Прежний `200.165.238.247.sslip.io` сохраняется для существующих ссылок.
Работают шесть сервисов: Caddy, web, queue, scheduler, PostgreSQL 16 и Redis.
Текущий подтверждённый выпуск и ссылки на CI/deploy — в [CURRENT-STATE](CURRENT-STATE.md).
История отдельных выкладок сохранена в [архиве](archive/VPS-DEPLOYMENT-2026-10-29.md).

## Что сохранять в окружении

`/opt/tenderfinder/.env.production` хранится только на VPS с правами `600`.
Исходники передаются через GitHub Actions; на VPS нет `.git` и доступа
к приватному Git remote. Не заменять env-файл содержимым рабочей копии.

Основная конфигурация: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://tenderfinder.ru`, PostgreSQL по `DB_HOST=db`, Redis по
`REDIS_HOST=redis`, `CACHE_STORE/QUEUE_CONNECTION/SESSION_DRIVER=redis`,
`SESSION_SECURE_COOKIE=true`. Секреты — `APP_KEY`, пароли БД, Telegram token,
webhook secret, readiness token и ключи источников — не печатать и не коммитить.
Имена остальных настроек приведены в `.env.example`.

`APP_DOMAIN="200.165.238.247.sslip.io, tenderfinder.ru"` сохраняет оба имени;
после запятой нужен пробел для Caddy. DNS и сертификат основного домена уже
настроены, повторный cutover при обычном релизе не требуется.

- `LOCAL_MVP_FULL_ACCESS_ENABLED` на VPS выключен.
- Старое окно `TEMP_FULL_ACCESS_UNTIL=2026-10-28T07:24:20Z` истекло;
  не продлевать его как часть обычного выпуска.
- Реальная ЮKassa выключена. Для demo действует отдельный flag и временная
  reviewer-сессия: [DEMO-CHECKOUT](DEMO-CHECKOUT.md).
- Настройки действующих источников и согласий не менять попутно с деплоем.

## Перед каждым выпуском приложения

1. Сверить `main` обоих репозиториев (`slsforme/saas-bot` и
   `Oleqq/tender-finder`) и изменения коллеги. Опубликовать один проверенный
   commit в оба remote, дождаться успешного CI **этого SHA** в обоих.
   CI проверяет PostgreSQL-тесты, JavaScript, TypeScript/Vite, PHPStan,
   Pint, ESLint и `git diff --check`.
2. Сверить установленный `.release-commit` и исходники с ожидаемым предыдущим
   релизом. До замены сохранить дамп PostgreSQL, приватные файлы, предыдущие
   исходники, env, marker и образ с отдельным тегом для отката.
3. Для backup использовать закрытый каталог, `umask 077`, права файлов `600`.
   Проверить результат `pg_dump`, размер и `gzip -t` архивов. Один дамп БД
   не включает загруженные файлы. Проверить возможность восстановления;
   без проверенной копии выпуск не начинать.
4. Сохранить хеш env и убедиться, что release archive не содержит локальных
   env, токенов или QA-баз. Для перехода со старого слоя контейнера на
   `application_private` сначала отдельно скопировать приватный каталог;
   не заменять старый web до переноса и проверки файлов.

## Штатная установка

Запустить существующий workflow **Deploy to VPS** на проверенном `main`
основного репозитория. Workflow загружает исходники в
`/opt/tenderfinder-release`, копирует их в каталог приложения и вызывает
`sh deploy/vps-deploy.sh`. Успешный CI другого SHA не подтверждает этот релиз.

Скрипт собирает также профилированный migrate-образ, проверяет PHP перед
миграциями, выполняет forward-only миграции и пересоздаёт сервисы.
Требуются PHP 8.3.36+ в ветке 8.3 и выключенный `session.upload_progress`.
Если используется `TENDER_FINDER_USE_LOCAL_PHP_BASE=1`, локальный base image
должен пройти ту же проверку. Обходить её старым образом нельзя.

Скрипт сохраняет двухстековую egress-сеть для Telegram. Общий
`application_private` подключён к web, queue, scheduler и migrate в
`/var/www/html/storage/app/private`. PostgreSQL/Redis не публикуются наружу.
Не выполнять `docker compose down -v` и не удалять тома при обычном релизе.

## После установки

Команды выполняются из `/opt/tenderfinder` с действующим env:

```sh
docker compose --env-file .env.production -f compose.production.yml ps
docker compose --env-file .env.production -f compose.production.yml exec -T web php artisan migrate:status
docker compose --env-file .env.production -f compose.production.yml exec -T web php artisan schedule:list
```

Проверить:

- Все шесть сервисов работают, web/БД/Redis healthy, PHP и upload progress
  соответствуют требованиям. Миграции текущего выпуска имеют статус `Ran`.
- Хеши tracked-исходников на хосте и изменённого рабочего кода в контейнере
  соответствуют выбранному SHA; env не изменился. Проверять ресурсы по
  production Vite manifest, поскольку имена локальной сборки могут отличаться.
- `/health` отвечает `ok` с VPS и внешней сети, нужные JS/CSS отдают 200,
  публичные тарифы открываются, защищённые страницы без сессии требуют входа.
  Отдельный `/ops/readiness` с настроенным Bearer token проверяет PostgreSQL
  и Redis; без действующего токена намеренно возвращает 404. Токен не писать
  в командную строку или протокол.
- Приватный том общий для web/queue/scheduler, имеет корректные права и не
  заменён пустым слоем контейнера. Проверку записи делать временным файлом
  с удалением, не загрузкой от имени живого пользователя.
- Новая функция прошла изолированный smoke без изменения реальных подписок,
  пользовательских записей и без незапрошенных Telegram-отправок.
  Подробности повторяемой приёмки — [TESTING](TESTING.md).

Только после этих проверок атомарно заменить `.release-commit` точным SHA.
Сам deploy workflow маркер не обновляет. Сохранить протокол выпуска отдельно
от инструкции; не переписывать marker ради видимости успешного деплоя.

При откате кода новые таблицы и приватный том сохраняются. Автоматически
запускать обратные миграции нельзя; восстановление БД/файлов — отдельная
операция с проверкой совместимости и возможной потери новых данных.

Документационные правки не требуют переустановки приложения, если не меняются
используемые runtime файлы, шаблоны или HTML-артефакты тарифов. В таком случае
коммит документов может быть новее установленного приложения.

## Telegram и диагностика загрузки

Действующий webhook — `/telegram/webhook`, без префикса `/api`.
В `getWebhookInfo` проверяются адрес, отсутствие последней ошибки и очередь.
Bot API secret должен совпадать с `TELEGRAM_WEBHOOK_SECRET`. При настройке
сохранить обработку `message`, `pre_checkout_query` и `subscription`;
старый checkout получает явный отказ. [События Stars](TELEGRAM-STARS-RUNBOOK.md).

Laravel пишет JSON в Docker и ротационный файл `application_logs` (14 дней).
Caddy хранит JSON access log в `caddy_logs`: 10 МБ, до пяти старых файлов,
до 14 дней. Docker хранит до пяти файлов по 10 МБ на контейнер.

```sh
docker compose --env-file .env.production -f compose.production.yml logs --since=30m web queue scheduler
docker compose --env-file .env.production -f compose.production.yml exec -T caddy tail -n 200 /var/log/caddy/access.log
```

Для зависания записать время и сеть устройства; сопоставить `/open`,
`/telegram/session`, `/build/*` и `X-Request-ID` между Caddy и Laravel.
Если `/open` отсутствует, проверять DNS/маршрут/TLS и открытие из Telegram.
Если запрос пришёл — статус сессии, JS и загрузку ресурсов. Ответ `/health`
сам по себе не доказывает загрузку Mini App. Причина всех прежних сетевых
сбоев не установлена; [старый инцидент](archive/MOBILE-LOAD-INCIDENT-2026-10-19.md)
сохранён для сравнения.

В структурированных HTTP-логах опущены query string, headers и body;
IP маскируется. Встроенный PHP-сервер дополнительно печатает обычные строки
запросов, которые могут содержать query string: доступ к Docker-логам ограничен.
Не прикладывать `initData`, cookies, ключи и signed reviewer-ссылки к отчётам.

## Секреты GitHub Actions

| Secret | Назначение |
|---|---|
| `TENDER_FINDER_VPS_HOST` | Адрес VPS. |
| `TENDER_FINDER_VPS_USER` | Пользователь выпуска. |
| `TENDER_FINDER_VPS_DEPLOY_KEY` | Приватный SSH-ключ. |
| `TENDER_FINDER_VPS_HOST_FINGERPRINT` | SHA256 fingerprint ключа хоста. |

Значения задаёт администратор репозитория; они не относятся к документации
и не должны попадать в Git. Платёжный запуск — отдельный
[план](PAYMENTS-NEXT-STAGE.md), а не настройка обычного деплоя.
