# Tender Finder

Laravel 12 application with React, TypeScript, Vite and Inertia. The current
scope is project infrastructure only; tender processing, Telegram, payments,
RSS and Mini App authentication are deliberately not implemented yet.

## Local setup (Laravel Herd)

Prerequisites: PHP 8.3+, Composer, Node.js 20+, PostgreSQL 16 and Redis.
Create a local PostgreSQL database named `tender_finder`, then configure the
credentials in `.env`.

```powershell
Copy-Item .env.example .env
php artisan key:generate
composer install
npm ci
php artisan migrate
npm run build
php artisan serve
```

Herd can serve the project as `http://tenderfinder.test`; update `APP_URL` if
you use a different hostname. Redis is used for cache, queues and sessions.

## Verification

```powershell
php artisan migrate:status
composer test
composer lint
composer analyse
npm run lint
npm run format:check
npm run build
```

With the server running, `GET /health` returns the application status as JSON.

## Configuration

`.env.example` contains only local development defaults and placeholders. Do
not commit `.env` or any credentials. GitHub Actions supplies an isolated
PostgreSQL and Redis instance for each push to `main`.
