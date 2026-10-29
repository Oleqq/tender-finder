# TenderFinder

Telegram Mini App для поиска закупок, подготовки заявок и работы команды.
Стек: Laravel 12, React/TypeScript, Inertia, PostgreSQL 16, Redis и Docker Compose.
Основной адрес — https://tenderfinder.ru.

## С чего начать

- [Текущее состояние](docs/CURRENT-STATE.md) — что выпущено и какие ограничения остаются.
- [Ближайшие задачи](docs/NEXT-FEATURES.md) — приёмка и зависимости следующих работ.
- [Индекс документов](docs/README.md) — описание нужной функции или инструкция.

Первые версии всех восьми функций Pro / Ultra выпущены: прайс, проверка
расходов, вопросы заказчику, предложения из блоков, поставки победителям,
объединение закупок, повторные закупки и контроль прибыли при исполнении.
Область каждой версии — в [карте функций](docs/PRO-ULTRA-FEATURE-ROADMAP.md).

Работают публичные источники и ограниченные каталоги площадок; полнота
поиска не обещается. Развитие RosTender отложено. Прямой сбор ЕИС выведен
из эксплуатации, исторические данные сохранены.

## Доступ и оплата

Триал длится 72 часа, после истечения платные действия блокируются.
Защищённый [демонстрационный checkout](docs/DEMO-CHECKOUT.md) позволяет
проверить заказ и выдачу доступа без денег. Реальная ЮKassa выключена,
Stars выведены из эксплуатации. Публичные тарифы показывают цены и не
принимают оплату. До продаж остаются [платёжная интеграция](docs/PAYMENTS-NEXT-STAGE.md)
и [юридические решения](docs/LEGAL-PRODUCTION-HANDOFF.md).

## Локальный запуск

Создайте `deploy/local-runtime.env` из `deploy/local-runtime.example.env`,
заполните локальные значения и не добавляйте файл в Git:

```sh
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
```

Локальный вход: `http://127.0.0.1:8080/local/mvp-subscriber`.

## Проверки

```sh
make check
npm run test:ui
```

PHP-тесты запускаются в изолированном test-сервисе. Полный набор на PostgreSQL
выполняет CI; постоянную локальную и production-базу для тестов не использовать.
Подробности: [вводная разработчика](docs/NEW-DEVELOPER.md),
[локальный контур](docs/LOCAL-RUNTIME.md), [приёмка](docs/TESTING.md)
и [выпуск на VPS](docs/VPS-DEPLOYMENT.md).
