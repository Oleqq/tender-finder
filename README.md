# Tender Finder

Tender Finder — будущий Telegram SaaS для поиска и отбора тендеров. Сейчас
рабочая часть проекта — **local MVP поиска в ЕИС**, а не публичный сервис.

В Docker можно открыть `http://127.0.0.1:8080/local/mvp-operator`, ввести
фразу и получить до десяти RSS-страниц ЕИС. Карточки нормализуются,
фильтруются по предмету закупки и сохраняются без дублей. Последняя выдача,
история и личные статусы сохраняются отдельно для каждого пользователя.
Автоматический мониторинг, Telegram-уведомления, платежи и production-доступ
не включены.

Полная и актуальная картина: [docs/CURRENT-STATE.md](docs/CURRENT-STATE.md).

## Локальный запуск

Создайте локальный `deploy/local-runtime.env` из шаблона и не добавляйте его в
Git. Затем:

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
```

Откройте `http://127.0.0.1:8080/local/mvp-operator`.

## Проверки

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web php artisan test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/pint --test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/phpstan analyse --memory-limit=1G
npm run build
```

Подробные инструкции: [вводная для разработчика](docs/NEW-DEVELOPER.md),
[локальный runtime](docs/LOCAL-RUNTIME.md),
[локальный Telegram-бот](docs/LOCAL-TELEGRAM-BOT.md) и
[источник ЕИС](docs/RSS-MVP-SOURCE.md).
