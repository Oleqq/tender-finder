# Вводная для разработчика

## Прочитать сначала

[CURRENT-STATE](CURRENT-STATE.md) → [NEXT-FEATURES](NEXT-FEATURES.md) → документ
своей задачи из [индекса](README.md). Архив и все описания функций заранее
читать не нужно. Старые «ещё не выпущено» в архиве не являются текущим статусом.

## Продукт и код

Laravel 12, PHP 8.3, PostgreSQL 16, Redis, React/TypeScript, Inertia и Vite.
Первые версии восьми функций Pro / Ultra работают вместе с мониторингами,
участием, командами и поддержкой. Реальная оплата выключена; есть защищённое
внешнее demo. RosTender отложен, прямой ЕИС выведен из эксплуатации.

| Область | Где искать |
|---|---|
| Мониторинги и поиск | `SearchQueryController`, `SearchQueryService`, `MonitoringPreviewService`, `TenderMatchingService`, `TenderRuleScore`. |
| Источники и свежесть | `app/Tenders/`, source-сервисы в `app/Services/`, `MonitoringStatusService`. |
| Доступ и demo | `AccessService`, `SubscriptionCatalog`, `SubscriptionOrderService`, `DemoCheckoutController`. |
| Восемь функций | Ссылки на сервисы, таблицы и тесты приведены в профильных документах из индекса. |
| Telegram | `app/Telegram/`, `TelegramIdentityService`, `TelegramBotClient`. |
| Поддержка | `SupportTicketService`, страницы `Support*` / `AdminSupport*`. |
| Интерфейс | `resources/js/Pages/`, общие компоненты, `resources/css/`. |
| Проверки | `tests/Feature/`, `tests/Unit/`, `tests/js/`, `.github/workflows/ci.yml`. |

Таблицы выведенных интеграций и исторические платежные поля сохраняются для
совместимости и истории. Не возобновлять старые writers/сетевые вызовы по
архивному документу.

## Запуск и проверки

Создать `deploy/local-runtime.env` из шаблона с отдельными локальными
значениями. Затем:

```sh
make doctor
make build
make migrate
make up
make check
npm run test:ui
```

Локальный full-access вход — `http://127.0.0.1:8080/local/mvp-subscriber`.
Он не проверяет платный доступ обычного пользователя: для этого нужны
отдельные fixtures или demo с выключенными full-access overrides.
Подробности — [LOCAL-RUNTIME](LOCAL-RUNTIME.md), [TESTING](TESTING.md).

## Правила

- Не коммитить env, ключи, webhook secrets, raw `initData`, QA-базы и реальные
  пользовательские документы. В тестах — обезличенные fixtures.
- Не обходить CAPTCHA, авторизацию, ограничения источников и TLS.
- Не отправлять материалы внешнему LLM без согласованной схемы обработки и бюджета.
- Тесты не запускать на постоянной локальной или production-БД.
- `docker compose down -v` удаляет локальные данные; для обычной остановки он не нужен.
- `LOCAL_MVP_FULL_ACCESS_ENABLED` допустим только в local/testing;
  истёкший production-доступ попутно не продлевать.
- Для runtime-выпуска сверить оба main и CI точного SHA, backup и smoke:
  [VPS-DEPLOYMENT](VPS-DEPLOYMENT.md).

Текущий статус обновлять в одной сводке, детали — в документе функции;
не добавлять один и тот же дневник изменений в несколько файлов.
