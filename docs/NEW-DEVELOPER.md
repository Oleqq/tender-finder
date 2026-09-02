# Tender Finder: вводная для нового разработчика

## За минуту

Tender Finder — будущий Telegram SaaS для поиска тендеров. Рабочий контур
сегодня — local MVP ручного поиска по RSS ЕИС в Docker. Это не production и
не автоматический мониторинг.

Стек: PHP 8.3, Laravel 12, PostgreSQL 16, Redis, React, TypeScript, Inertia,
Vite и Docker Compose. Telegram-код написан на Laravel; Telegraph не
подключён.

## Что реально сделано

| Область | Готово сейчас | Не считать готовым |
|---|---|---|
| ЕИС | Ручной RSS-поиск по фразе; режимы релевантности и минус-слова; 44/223‑ФЗ, НМЦК, дата, этапы, дополнительная информация, до 5 регионов КЛАДР и до 5 ОКПД2; до 10 страниц; сохранение полного набора условий и повторный запуск | Полный каталог ЕИС, monitoring |
| Карточки | Понятные поля, decision-detail, дедупликация, сравнение 2–5 карточек, личные статусы и bulk actions; явное обогащение только из разрешённой публичной карточки ЕИС | Выдуманные поля, HTML-скрапинг, обогащение без публичного контракта источника |
| Данные | Последняя выдача, последние 20 запусков сохранённого запроса, «только новые», личные статусы, заметки, теги, дата действия и CSV/XLSX привязаны к пользователю и переживают refresh/restart | Общая история всех пользователей или удаление Docker volume |
| Identity | Подписанный Telegram `initData`; IDs из `TELEGRAM_SUPERADMIN_IDS` → `super_admin` с ЕИС-workspace/«Аналитикой», остальные → `subscriber`; только local/test флаг временно делает любой локальный вход full-access | Login Widget вместо Mini App, доверие к Telegram ID из браузера или `/start` как авторизации |
| Access | `preview`, `trialing`, `paid`, `granted`, `expired`; one-time 72h trial после consent; local full-access без создания подписки | Роль `subscriber_trial`, рабочая оплата Stars, включение full-access на Railway/VPS |
| Админка | Закрытая маркетинговая аналитика `super_admin`: аудитория, trial и доступы без персональных данных | Полноценные Users/Commerce/Campaigns, технический мониторинг или реальная выручка |
| Бот | Webhook, дедупликация обновлений, `/start`, `/help`, очередь отправки | Публично включённый webhook, рассылки и Stars |

## Где искать код

- `app/Tenders/` — контракт и разбор RSS ЕИС.
- `app/Services/LocalMvp*` — ручной поиск, запуск сохранённых запросов, снимки
  выдачи, личные статусы, аннотации и локальный full-access режим.
- `EisTenderEnrichmentService`, `TenderExportService` — публичное обогащение
  одной карточки и выгрузка уже сохранённых данных.
- `app/Telegram/`, `TelegramIdentityService`, `TelegramBotClient` — проверка
  Mini App и Bot API.
- `app/Services/TrialService`, `AccessService` — consent, trial и права.
- `resources/js/Pages/MvpWorkspace.tsx` — local MVP; `Consents.tsx` — trial
  flow; `OperationsDashboard.tsx` — агрегированная аналитика администратора.
- `tests/Feature/` — главный источник проверяемого поведения.

## Первые команды

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
docker compose -f compose.local.yml -f compose.local.dev.yml run --rm test
```

Локальный оператор: `http://127.0.0.1:8080/local/mvp-operator`.
Технический subscriber: `http://127.0.0.1:8080/local/mvp-subscriber`.
Подробности: [LOCAL-RUNTIME.md](LOCAL-RUNTIME.md) и
[LOCAL-TELEGRAM-BOT.md](LOCAL-TELEGRAM-BOT.md). Состав принятых веток:
[INTEGRATION-STATUS.md](INTEGRATION-STATUS.md).

## Неподвижные правила

- Не читать и не коммитить `deploy/local-runtime.env`, токены, cookies,
  webhook secrets, raw `initData`, данные живых закупок и URL с фильтрами.
- Не отключать TLS для ЕИС, не применять CAPTCHA обход, cookies, прокси-ротацию
  или случайный HTML-скрапинг.
- Не включать `RSS_LIVE_POLLING_ENABLED`; это отдельное решение владельца.
- Не использовать `docker compose down -v`, если не согласована очистка
  локальной базы: эта команда удаляет PostgreSQL volume.
- Trial нельзя запускать до публикации юридических документов; это намеренный
  fail-closed gate.
- `LOCAL_MVP_FULL_ACCESS_ENABLED` — только для local/test приёмки. Перед
  Railway/VPS он должен быть выключен или отсутствовать.

## Как проверять изменения

```powershell
npm run build
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

Laravel-тесты запускаются только через отдельный Compose-сервис `test` с
SQLite `:memory:`. GitHub Actions использует отдельную PostgreSQL-базу
`tender_finder_testing`. Встроенный fail-safe останавливает suite до миграций,
если окружение не `testing` или PostgreSQL-база не имеет суффикса `_testing`.

Перед работой со следующей функцией сначала обновляйте
[CURRENT-STATE.md](CURRENT-STATE.md), а историческое
[ТЗ](ТЗ%20бот%20тг%2Bподписка.md) не используйте как статус выполненных работ.
