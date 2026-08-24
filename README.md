# Tender Finder

Telegram Mini App для поиска, мониторинга и объяснимого отбора тендеров.
Проект собран как один Laravel 12-репозиторий: Laravel обслуживает серверную
логику, Telegram, сессии, webhook и Inertia endpoints; React + TypeScript +
Inertia создают быстрый mobile-first SPA-интерфейс без React Router.

## Текущий статус

- Работает foundation Mini App: светлая и тёмная темы, Telegram theme
  parameters, safe-area, AppShell и demo-экраны запуска, onboarding,
  согласий, dashboard, тендеров и профиля.
- Production: <https://tender-finder-navy.vercel.app/>. `GET /health`
  возвращает JSON со статусом приложения.
- Для бота настроена menu button, открывающая production Mini App. Это
  безопасный demo-просмотр до включения серверной Telegram-авторизации.
- Серверная foundation уже реализована и протестирована: verified Telegram
  session, согласия, разовый 72-часовой trial, webhook, query domain, RSS
  fixtures и matching. Она ещё не включена в production: для этого нужны VPS,
  managed PostgreSQL/Redis, legal URLs и операторский smoke-test.

## Локальный запуск (Laravel Herd)

Нужны PHP 8.3+, Composer, Node.js 20+, PostgreSQL 16 и Redis. Создайте
локальную PostgreSQL-базу `tender_finder`, затем настройте локальные данные в
`.env`.

```powershell
Copy-Item .env.example .env
php artisan key:generate
composer install
npm ci
php artisan migrate
npm run build
```

Herd обслуживает проект по `http://tenderfinder.test`; при другом hostname
обновите `APP_URL` только в локальном `.env`.

## Проверки

```powershell
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run lint
npm run format:check
npm run build
```

## Продуктовая модель

Ролей будет ровно две: `subscriber` и `super_admin`. План, trial и статус
доступа — отдельные сущности, а не дополнительные роли. Супер-админ открывает
административные экраны прямо внутри Mini App; сервер назначает это право
только после проверки Telegram `initData` и сопоставления с защищённой
конфигурацией, а не по данным клиента.

Первой оплатой внутри Telegram будет Telegram Stars. Базовый план даёт
объяснимые фильтры и мониторинг; персональный LLM-scoring и анализ ТЗ —
следующий PRO-этап после валидации качества, стоимости и правил работы с
данными.

Подробности:

- [рабочая карта продукта](docs/README.md);
- [технический план](docs/TECHNICAL-PLAN.md);
- [roadmap B2C, тарифов, Stars и админ-раздела](docs/PRODUCT-ROADMAP.md);
- [дизайн-система и каталог UI-компонентов](docs/DESIGN-SYSTEM.md);
- [понятная схема БД и технический справочник](docs/DATABASE.md);
- [VPS cutover и rollback runbook](docs/DEPLOYMENT.md);
- [текущее состояние и журнал](docs/PROGRESS.md).

`.env` и любые учётные данные не входят в репозиторий. GitHub Actions
поднимает изолированные PostgreSQL и Redis для проверок на `main`.
