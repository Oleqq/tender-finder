# Развёртывание в Vercel

## Назначение

Vercel обслуживает Laravel-приложение как PHP serverless function и выдаёт HTTPS-адрес для Telegram Mini App и webhook. Конфигурация находится в `vercel.json`; в панели Vercel выберите framework preset `Other` и корневую директорию `./`.

Сборка публикует только скомпилированные Vite-ассеты в `dist/build`. Все HTTP-маршруты приложения направляются в Laravel serverless function.

## Production environment variables

Значения задаются в Vercel для Production и не хранятся в репозитории.

| Переменная | Значение |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Новый ключ Laravel, хранится как sensitive variable |
| `APP_URL` | Production URL проекта Vercel |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `warning` |
| `SESSION_DRIVER` | `redis` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `redis` |
| `QUEUE_CONNECTION` | `redis` |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Данные managed PostgreSQL |
| `REDIS_CLIENT` | `phpredis` |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD` | Данные managed Redis |

После создания базы данных выполните миграции из доверенного окружения с этими production-переменными. Не добавляйте `.env`, токены, пароли или ключи в Git.

## Проверка

После production deploy проверьте `GET /health`. Ожидаемый ответ:

```json
{"status":"ok","application":"Tender Finder"}
```
