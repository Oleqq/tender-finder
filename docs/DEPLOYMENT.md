# Развёртывание в Vercel

## Назначение

Vercel обслуживает Laravel-приложение как PHP serverless function и выдаёт HTTPS-адрес для Telegram Mini App и webhook. Конфигурация находится в `vercel.json`; в панели Vercel выберите framework preset `Other` и корневую директорию `./`.

Сборка публикует только скомпилированные Vite-ассеты в `dist/build`. Все HTTP-маршруты приложения направляются в Laravel serverless function.

## Production environment variables

Значения задаются в Vercel для Production и не хранятся в репозитории. Не импортируйте `.env.example` как готовую production-конфигурацию: шаблон содержит локальные адреса и пустые секреты.

| Переменная | Значение |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Новый ключ Laravel, хранится как sensitive variable |
| `APP_URL` | Production URL проекта Vercel |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `warning` |
| `SESSION_DRIVER` | `redis` после подключения managed Redis |
| `SESSION_SECURE_COOKIE` | `true` после подключения managed Redis |
| `CACHE_STORE` | `redis` после подключения managed Redis |
| `QUEUE_CONNECTION` | `redis` после подключения managed Redis |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Данные managed PostgreSQL |
| `REDIS_CLIENT` | `phpredis` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD` | Данные managed Redis |

## Ограничения serverless runtime

Файловая система Vercel-функции неизменяема. До подключения managed Redis и PostgreSQL допустим только технический bootstrap-режим: `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, а пути Laravel cache и compiled views направляются в `/tmp`. Этот режим нужен исключительно для проверки запуска; он не сохраняет сессии и не подходит для Telegram-онбординга, trial или очередей.

Для Laravel в Vercel также задаются пути `APP_CONFIG_CACHE`, `APP_EVENTS_CACHE`, `APP_PACKAGES_CACHE`, `APP_ROUTES_CACHE`, `APP_SERVICES_CACHE` и `VIEW_COMPILED_PATH` в `/tmp`. Не задавайте `PHP_CLI_SERVER_WORKERS`: PHP runtime Vercel управляет процессами самостоятельно.

Имя `LOG_CHANNEL` нормализуется приложением перед выбором канала, поэтому случайный завершающий перевод строки в панели окружения не переключит Laravel на файловый emergency log.

После создания базы данных выполните миграции из доверенного окружения с этими production-переменными. Не добавляйте `.env`, токены, пароли или ключи в Git.

## Проверка

После production deploy проверьте `GET /health`. Ожидаемый ответ:

```json
{"status":"ok","application":"Tender Finder"}
```
