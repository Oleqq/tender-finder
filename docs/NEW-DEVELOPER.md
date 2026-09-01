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
| ЕИС | Ручной RSS-поиск по фразе; режимы все/любое/точная фраза, минус-слова и причины совпадения; 44/223‑ФЗ, НМЦК, дата, этапы закупки и проверенная дополнительная информация; до 10 страниц; сохранение полного набора условий и повторный запуск одной кнопкой | Полный каталог ЕИС, регион/ОКПД2 без RSS-ссылки, monitoring |
| Карточки | Понятные поля, detail, дедупликация, личные статусы и bulk actions | Выдуманные поля, HTML-скрапинг, обогащение без публичного контракта источника |
| Данные | Последняя выдача, история и статусы привязаны к пользователю и переживают refresh/restart | Общая история всех пользователей или удаление Docker volume |
| Identity | Подписанный Telegram `initData`; IDs из `TELEGRAM_SUPERADMIN_IDS` → `super_admin` с ЕИС-workspace/«Операции», остальные → `subscriber` | Login Widget вместо Mini App, доверие к Telegram ID из браузера или `/start` как авторизации |
| Access | `preview`, `trialing`, `paid`, `granted`, `expired`; one-time 72h trial после consent | Роль `subscriber_trial`, рабочая оплата Stars |
| Админка | Закрытый `super_admin` экран агрегатов доступа без персональных данных | Полноценные Users/Commerce/Campaigns или реальная выручка |
| Бот | Webhook, дедупликация обновлений, `/start`, `/help`, очередь отправки | Публично включённый webhook, рассылки и Stars |

## Где искать код

- `app/Tenders/` — контракт и разбор RSS ЕИС.
- `app/Services/LocalMvp*` — ручной поиск, запуск сохранённых запросов, снимки
  выдачи и личные статусы.
- `app/Telegram/`, `TelegramIdentityService`, `TelegramBotClient` — проверка
  Mini App и Bot API.
- `app/Services/TrialService`, `AccessService` — consent, trial и права.
- `resources/js/Pages/MvpWorkspace.tsx` — local MVP; `Consents.tsx` — trial
  flow; `OperationsDemo.tsx` — агрегаты администратора.
- `tests/Feature/` — главный источник проверяемого поведения.

## Первые команды

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web php artisan test
```

Локальный оператор: `http://127.0.0.1:8080/local/mvp-operator`.
Технический subscriber: `http://127.0.0.1:8080/local/mvp-subscriber`.
Подробности: [LOCAL-RUNTIME.md](LOCAL-RUNTIME.md) и
[LOCAL-TELEGRAM-BOT.md](LOCAL-TELEGRAM-BOT.md).

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

## Как проверять изменения

```powershell
npm run build
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

Перед работой со следующей функцией сначала обновляйте
[CURRENT-STATE.md](CURRENT-STATE.md), а историческое
[ТЗ](ТЗ%20бот%20тг%2Bподписка.md) не используйте как статус выполненных работ.
