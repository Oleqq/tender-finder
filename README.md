# Tender Finder

Tender Finder — Telegram Mini App для поиска, отбора и командной работы с
тендерами. Основной источник пользовательских мониторингов — официальный API
RosTender с сохранёнными шаблонами. Дополнительно доступны отключённые по
умолчанию публичные источники СБЕР АСТ, Workspace.ru и B2B-Center. Прямой сбор, RSS-поиск
и разбор страниц `zakupki.gov.ru` удалены 26 сентября 2026 года.

Приложение поддерживает персональную и командную ленту, объяснимый локальный
score, статусы и фильтры, участие, чек-листы, календарь, экономику заявки,
go/no-go согласование и Telegram-уведомления. Исторические записи прежнего
источника сохраняются только для аудита и пользовательской истории.
Обращения в поддержку создаются и читаются в Mini App; суперадминистратор
видит очередь, отвечает и диагностирует доступ без содержимого тендеров.

Актуальное состояние: [docs/CURRENT-STATE.md](docs/CURRENT-STATE.md).

## Локальный запуск

Создайте `deploy/local-runtime.env` из шаблона и не добавляйте его в Git:

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
```

Откройте `http://127.0.0.1:8080/local/mvp-subscriber`.

## Проверки

```powershell
make test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/pint --test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/phpstan analyse --memory-limit=1G
npm run build
```

Подробности: [вводная для разработчика](docs/NEW-DEVELOPER.md),
[локальный runtime](docs/LOCAL-RUNTIME.md) и
[локальный Telegram-бот](docs/LOCAL-TELEGRAM-BOT.md).
