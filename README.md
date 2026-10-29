# Tender Finder

Tender Finder — Telegram Mini App для поиска, отбора и командной работы с
тендерами. Мониторинг работает с включёнными публичными источниками без шаблона
RosTender; официальный API RosTender можно подключить дополнительно. На VPS
работают Workspace.ru, B2B-Center и ограниченные каталоги Росэлторга,
РТС-Тендер и Сбер АСТ, опубликованные на B2B-Center. Прямые реестры этих трёх
площадок пока не подключены. Прямой сбор, RSS-поиск
и разбор страниц `zakupki.gov.ru` удалены 26 сентября 2026 года.

Приложение поддерживает персональную и командную ленту, объяснимый локальный
score, статусы и фильтры, участие, чек-листы, календарь, экономику заявки,
go/no-go согласование и Telegram-уведомления. Исторические записи прежнего
источника сохраняются только для аудита и пользовательской истории.
Обращения в поддержку создаются и читаются в Mini App; суперадминистратор
видит очередь, отвечает и диагностирует доступ без содержимого тендеров.

Актуальное состояние: [docs/CURRENT-STATE.md](docs/CURRENT-STATE.md).

В рабочей копии реализован [PRO-03: вопросы заказчику](docs/TENDER-QUESTIONS.md)
для Pro / Ultra: цитаты карточки и PRO-02, редактируемые черновики, копирование,
ручная отметка отправки, ответы и история. Публикация на VPS — отдельный этап.

В рабочей копии реализован [PRO-02: проверка неучтённых расходов](docs/COST-GAP-CHECK.md)
для Pro / Ultra: документы или текст, цитаты, ручные выводы и однократный перенос
подтверждённых сумм в экономику заявки. Публикация на VPS — отдельный этап.

## Перед публичным и платным запуском

Оплата Stars выведена из приложения: новые счета не создаются, а входящие
запросы Telegram на подтверждение платежа отклоняются. Изменения пока в
рабочей копии и требуют отдельного выпуска на VPS. Другой платёжный канал
ещё не подключён. Текущие решения и дальнейший путь описаны в
[плане оплаты](docs/PAYMENTS-NEXT-STAGE.md) и
[инструкции по VPS](docs/VPS-DEPLOYMENT.md). Для юриста подготовлен
[перечень документов и решений](docs/LEGAL-PRODUCTION-HANDOFF.md);
утверждённые юридические тексты ещё требуются.

В рабочей копии есть выключенный [серверный API-клиент ЮKassa](docs/YOOKASSA-INTEGRATION.md)
для будущей разовой оплаты на отдельном сайте. Публичного checkout и выдачи
доступа по ЮKassa пока нет.

Вся документация собрана в [индексе](docs/README.md).

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
