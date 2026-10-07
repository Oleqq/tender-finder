# Tender Finder: вводная для нового разработчика

## За минуту

Tender Finder — Laravel 12 + React/TypeScript Telegram Mini App. Мониторинги
могут работать с публичными источниками без шаблона RosTender. Официальный API
RosTender подключается дополнительно; на VPS его опрос ограничен квотой.
Работают Workspace.ru, B2B-Center и частичные каталоги трёх площадок через
B2B-Center. Прямой реестр Сбер АСТ остаётся выключенным. Интеграции с `zakupki.gov.ru` в
runtime нет: RSS-поиск, HTML/XML-парсеры, фоновые jobs и enrichment для него
удалены.

Стек: PHP 8.3, PostgreSQL 16, Redis, Inertia, Vite и Docker Compose.

## Где искать код

- `app/Tenders/Rostender*` и `app/Services/Rostender*` — источник и квоты.
- `app/Services/SberAst*`, `WorkspaceRu*`, `B2bCenter*` и `PlatformCatalog*` — публичные источники без
  пользовательской авторизации.
- `SearchQueryController`, `SearchQueryService`, `MonitoringPreviewService` —
  создание, изменение и предварительная проверка мониторинга.
- `TenderMatchingService` и `TenderRuleScore` — детерминированный match и score.
- `app/Telegram/`, `TelegramIdentityService`, `TelegramBotClient` — Mini App и
  Bot API.
- `resources/js/Pages/MyQueries.tsx` и `Tenders.tsx` — мониторинги и лента.
- `app/Services/SupportTicketService.php` и `resources/js/Pages/Support.tsx` —
  обращения; доступ администратора проверяется сервером.
- `tests/Feature/` — основной контракт поведения.

Таблицы прежнего источника не удаляются: они могут содержать исторические
данные production. Миграция retirement ставит старые ленты и мониторинги без
RosTender на паузу. Не добавляйте для них новые writers или сетевые вызовы.

## Первые команды

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
docker compose -f compose.local.yml -f compose.local.dev.yml run --rm --no-deps test
```

Локальный full-access вход: `http://127.0.0.1:8080/local/mvp-subscriber`.

## Неподвижные правила

- Не коммитить env-файлы, API-ключи, webhook secrets, raw `initData` и данные
  живых закупок.
- Не добавлять обход CAPTCHA, закрытых страниц, cookies или proxy rotation.
- Не отправлять пользовательские данные внешнему LLM без отдельного
  privacy/cost approval.
- Не применять `docker compose down -v` без явного решения удалить локальную БД.
- `LOCAL_MVP_FULL_ACCESS_ENABLED` допустим только в local/testing.

Полезные документы: [CURRENT-STATE](CURRENT-STATE.md),
[LOCAL-RUNTIME](LOCAL-RUNTIME.md), [ROSTENDER-INTEGRATION](ROSTENDER-INTEGRATION.md).
