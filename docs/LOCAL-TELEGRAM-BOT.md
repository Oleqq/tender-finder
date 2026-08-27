# Локальная проверка Telegram-бота

Этот документ нужен разработчику, который впервые подключается к Tender
Finder. Он описывает только текущий код и безопасную локальную проверку, а не
инструкцию по запуску публичной beta.

## Что здесь реализовано

Telegram-часть встроена в Laravel 12 — отдельного Python-сервиса и пакета
Telegraph нет. Путь сообщения выглядит так:

```text
Telegram webhook → POST /telegram/webhook → PostgreSQL (дедупликация)
                 → Redis queue → ProcessTelegramUpdate → Bot API sendMessage
```

- webhook принимает только `/start` и `/help`;
- `TelegramBotClient` вызывает официальный Bot API через Laravel HTTP client;
- `queue` отправляет ответ асинхронно;
- повтор одного `update_id` не создаёт повторное сообщение;
- Mini App создаёт web-сессию только после серверной проверки подписанного
  `Telegram.WebApp.initData`;
- пользователь получает роль `subscriber`; совпадение проверенного личного
  Telegram ID со списком `TELEGRAM_SUPERADMIN_IDS` даёт `super_admin`.
  Старый одиночный `TELEGRAM_OWNER_ID` поддержан для совместимости.

**Telegram Login Widget здесь не нужен.** Он предназначен для входа на
обычный внешний сайт, открытый вне Telegram. Main Mini App уже получает
подписанный `initData` от Telegram; сервер проверяет его тем же bot token.
Не настраивайте Login Widget вместо URL Main Mini App.

`/start` не авторизует веб-приложение и не запускает trial. Trial начинается
один раз, только после принятия опубликованных оферты и политики.

## Что потребуется

1. Docker Desktop и доступный локальный порт `8080`.
2. Тестовый Telegram-бот и отдельный тестовый Telegram-аккаунт.
3. Для реального webhook — временный публичный HTTPS-адрес, который
   проксирует запросы на `http://127.0.0.1:8080`. Обычный `localhost` Telegram
   не видит.

Не передавайте bot token, webhook secret, `APP_KEY`, cookies или URL с
секретами в Git, чат и тикеты.

## Запуск local runtime

1. Создайте `deploy/local-runtime.env` из
   `deploy/local-runtime.example.env` и заполните только на своём компьютере
   `APP_KEY`, PostgreSQL и Redis. Этот файл игнорируется Git.
2. Для проверки Telegram добавьте в этот же локальный файл имена переменных:

   ```dotenv
   TELEGRAM_BOT_TOKEN=<токен тестового бота>
   TELEGRAM_WEBHOOK_SECRET=<случайная длинная строка>
   TELEGRAM_SUPERADMIN_IDS=<личный ID владельца,ID второго администратора>
   ```

3. Поднимите контур:

   ```powershell
   docker compose -f compose.local.yml -f compose.local.dev.yml build
   docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
   docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
   docker compose -f compose.local.yml -f compose.local.dev.yml ps
   ```

`web` принимает HTTP-запросы, `queue` отправляет ответы бота, а `scheduler`
выполняет lifecycle-задачи. Не включайте
`RSS_LIVE_POLLING_ENABLED=true`: к проверке бота это не относится.

## Подключение webhook к тестовому боту

1. Поднимите временный HTTPS-туннель или обратный прокси, который направляет
   публичный HTTPS-адрес на локальный `127.0.0.1:8080`.
2. Укажите этот HTTPS-адрес как `APP_URL` в локальном файле конфигурации и
   перезапустите `web`, `queue` и `scheduler`.
3. В настройке webhook у Telegram задайте endpoint
   `https://<ваш-домен>/telegram/webhook` и тот же секретный header token,
   который находится в `TELEGRAM_WEBHOOK_SECRET`.
4. Отправьте тестовому боту `/start`, затем `/help`. Ответ появится только
   при работающем `queue`.

Передавать bot token в командной строке, URL или скриншоте не нужно. В список
администраторов вносятся личные Telegram **user ID**, а не ID группы или
канала: Mini App подтверждает именно пользователя. При следующем входе
удалённый из списка пользователь автоматически вернётся к роли `subscriber`.
Для реальной закрытой beta используйте [DEPLOYMENT.md](DEPLOYMENT.md).

## Проверка Mini App и trial

1. В локальном браузере можно безопасно открыть технические входы:
   `/local/mvp-operator` и `/local/mvp-subscriber`. Второй создаёт только
   local-dev пользователя и не подделывает Telegram ID.
2. В настоящем Telegram Mini App фронтенд отправляет подписанный `initData` в
   `POST /telegram/session`. Сервер создаёт или находит пользователя и
   обновляет страницу после смены session ID, чтобы получить актуальный CSRF
   token.
3. После consent вызываются `POST /consents` и `POST /trial/start`.
   Сейчас эти операции честно вернут `503`, пока
   `LEGAL_DOCUMENTS_PUBLISHED=false`. Не обходите это ограничение для
   локального smoke-test.

Если был открыт старый таб во время перезапуска `web`, приложение
перезагружает страницу при ответе CSRF `419` и получает новый токен. Если
сессия всё равно не обновилась, откройте Mini App заново; не отключайте CSRF.

## Быстрая диагностика

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml ps
docker compose -f compose.local.yml -f compose.local.dev.yml logs --tail=100 web queue
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web php artisan test
```

- `403` от webhook: отсутствует или не совпадает secret header token.
- `/start` принят, но нет ответа: проверить сервис `queue` и конфигурацию
  тестового bot token.
- `419 CSRF`: открыть Mini App заново; код не принимает устаревший токен и
  не создаёт trial при такой ошибке.
- `503` при запуске trial: это legal gate, а не ошибка Telegram.

## Границы текущей реализации

Сейчас нет работающих Telegram Stars, публичной рассылки тендеров, live RSS
monitoring и production webhook. Этот документ не является разрешением
включать их. Следующий этап — закрытый VPS smoke-test после публикации
юридических документов.
