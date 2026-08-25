# Hand-off для следующего чата

Ниже — готовый текст, который можно целиком вставить в новый чат Codex, чтобы
продолжить Tender Finder без потери контекста. Перед началом новый агент должен
сам проверить `git log -1 --oneline` и `git status`: это надёжнее, чем хранить
хеш последнего коммита прямо в hand-off файле.

```text
Продолжи Tender Finder в C:\dev\TenderFinder.

Сначала прочитай: docs/BEGINNER-GUIDE.md, docs/README.md,
docs/TECHNICAL-PLAN.md, docs/DATABASE.md, docs/DEPLOYMENT.md,
docs/PROGRESS.md и этот hand-off. Затем выполни git log -1 --oneline и git
status. Объясняй результаты в двух слоях: сначала простым языком для человека
с WordPress-бэкграундом, затем коротко технически. После каждого значимого
блока обновляй docs/README.md и docs/PROGRESS.md; при изменениях БД,
инфраструктуры, API или безопасности обновляй также профильный документ.

Git и безопасность:
- Работаем в main, историю не переписывать.
- Никогда не трогать, не добавлять и не коммитить dump.rdb,
  tmp-free-zakupki-monitor/, .env, токены, ключи, cookies или персональные
  данные.
- .env можно читать только если пользователь прямо разрешил это для конкретной
  цели; не выводить и не переносить значения в код, логи или документацию.
- Перед каждым commit обязательно: git diff --check; git status; поиск
  внутренних упоминаний только среди изменённых файлов.
- Перед каждым push обязательно: npm run build; php artisan test;
  vendor/bin/pint --test; vendor/bin/phpstan analyse --memory-limit=1G;
  npm run lint; npm run format:check.

Уже готово в коде, но ещё НЕ заявляется как включённое в production:
- Laravel 12 + Inertia + React/TypeScript/Vite; mobile-first Telegram Mini App
  с честным browser preview и demo-данными.
- UI: welcome/onboarding/consents/dashboard/tenders/profile/plans/queries,
  Basic/будущий PRO comparison, data-first component library и Operations demo.
- Нормализованная PostgreSQL schema foundation: users, consent_events, plans,
  subscriptions, entitlements, telegram_updates, search_queries, source_feeds,
  source_feed_items, tenders, tender_query_matches, notification_deliveries,
  source_runs. Роли только subscriber и super_admin; plan/access отдельно.
- POST /telegram/session проверяет raw подписанный initData и auth_date;
  owner ID берётся только из server-side конфигурации. Browser fallback
  анонимен и не получает серверный доступ.
- Append-only consents и one-time Basic trial на 72 часа с лимитом 3 active
  queries; серверная проверка запросов и паузы/freeze.
- Telegram webhook: secret check, update-id dedup, queue jobs /start и /help.
- RSS fixture parser/import, safe URL allowlist, first-poll silence,
  deterministic matching и антиспам-очередь. Live polling выключен до SRC-00.
- Docker/VPS foundation: Dockerfile, compose с web/queue/scheduler/Caddy,
  public /health и закрытый /ops/readiness. На локальной машине Docker CLI не
  установлен, поэтому container smoke-test ещё не выполнен.

Реальность production:
- Текущий Vercel Mini App: https://tender-finder-navy.vercel.app/ .
  /health отвечает 200, /plans отвечает 200. Бот уже открывает этот URL через
  menu button.
- Vercel остаётся demo и rollback. До успешного VPS smoke-test не переключать
  Mini App URL или Telegram webhook.
- Для настоящего server activation обязательны: VPS/domain, managed PostgreSQL,
  managed Redis, публичные offer/privacy URLs, Telegram secrets в secret store
  и тестовый Telegram-аккаунт. Не подставляй placeholders как будто это готовый
  прод.
- Настоящие Telegram sessions, webhook, /start, trial, RSS delivery и платежи
  не подтверждены в production. Telegram Stars, цены, refund lifecycle,
  admin mutations и live RSS пока не реализовывать/не включать без отдельного
  readiness/product gate.

Продуктовые правила:
- Ровно две роли: subscriber, super_admin.
- super_admin только после server-side проверки Telegram initData и сравнения
  verified ID с защищённой конфигурацией владельца; ID не писать в Git/docs.
- Админка только внутри Mini App, не отдельный публичный /admin.
- Basic: понятные фильтры, поиск, мониторинг, уведомления. Future PRO:
  увеличенные лимиты, затем scoring/анализ ТЗ лишь после quality/cost/privacy
  gate. Не обещать проценты роста шанса победы.
- Demo/example данные всегда явно помечать как demo/example.

Рекомендуемый порядок следующих этапов:
1. Не делать фиктивный deploy. Сначала получить от владельца VPS/domain,
   managed PostgreSQL/Redis и реальные публичные legal URLs. Подготовить
   secret store вне Git по docs/DEPLOYMENT.md.
2. На VPS выполнить Docker smoke-test: поднять web/queue/scheduler/Caddy,
   применить migrations к managed PostgreSQL, проверить /health и закрытый
   readiness с токеном. Подтвердить rollback на Vercel.
3. Выполнить real Telegram smoke-test с тестовым аккаунтом: signed initData,
   повторная сессия, consents, одноразовый trial, /start, /help, webhook secret
   и duplicate update. Только после успеха переключить menu URL/webhook.
4. Выполнить SRC-00: вручную подтвердить допустимые RSS-ленты ЕИС, поля,
   интервал и правила использования. До этого live RSS flag оставлять false.
5. Затем завершать Basic tender core на реальных данных: удобное редактирование
   queries, source telemetry, подтверждение first-poll silence/matching/digest
   и только потом read-only admin данные. Не рисовать «реальные» метрики из
   demo-заглушек.
6. Telegram Stars, subscriptions/refunds, admin mutations и campaigns — только
   отдельными этапами после соответствующих решений и tests.

В конце каждого законченного блока обнови документацию простым и техническим
языком, проведи нужные проверки, создай ясный conventional commit и push в оба
origin URL. Не утверждай, что production-функция живая, пока она не проверена
на настоящем VPS и Telegram.
```

## Что должен получить следующий агент до начала VPS-работы

| Нужная вещь | Почему без неё не стоит «включать кнопку» |
|---|---|
| VPS и домен | Нужен стабильный HTTPS runtime для `web`, `queue`, `scheduler` и Caddy. |
| Managed PostgreSQL | Хранит пользователей, trial, запросы и очередь доставки после перезапуска. |
| Managed Redis | Нужен для очередей, locks и фоновых задач. |
| Публичные legal URLs | Trial нельзя законно и технически стартовать без актуальных оферты и политики. |
| Секреты в secret store | Bot token, webhook secret, owner ID и readiness token не должны попасть в Git. |
| Тестовый Telegram-аккаунт | Без него нельзя честно проверить `initData`, webhook и сообщения бота. |

Подробная последовательность действий оператора находится в
[DEPLOYMENT.md](DEPLOYMENT.md), а состояние задач — в
[PROGRESS.md](PROGRESS.md).
