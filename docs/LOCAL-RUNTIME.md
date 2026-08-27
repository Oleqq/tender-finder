# Локальный Docker MVP

Этот контур предназначен для разработки и ручной проверки ЕИС. Он не является
VPS, публичным SaaS, Telegram-ботом или постоянным мониторингом.

## Запуск

1. Установите Docker Desktop.
2. Создайте локальный `deploy/local-runtime.env` из
   `deploy/local-runtime.example.env`. Не открывайте и не добавляйте его в Git.
3. Выполните:

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
docker compose -f compose.local.yml -f compose.local.dev.yml ps
```

Приложение: `http://127.0.0.1:8080`.
Рабочее место MVP: `http://127.0.0.1:8080/local/mvp-operator`.
Тестовый вход subscriber: `http://127.0.0.1:8080/local/mvp-subscriber`.
Vite HMR: `http://127.0.0.1:5173`.

## Ручной поиск ЕИС

1. Откройте local MVP.
2. Введите поисковую фразу без персональных и секретных данных.
3. При необходимости задайте 44‑ФЗ/223‑ФЗ, НМЦК и период публикации. Эти
   условия попадают в RSS-запрос ЕИС до загрузки карточек.
4. Выберите первую RSS-страницу, три, пять или до десяти страниц и нажмите
   «Найти в ЕИС».
5. Проверьте счётчики: число RSS-элементов, тематических карточек и новых
   записей. История не смешивается с текущей выдачей. Последний результат,
   история и личные отметки сохраняются за текущим пользователем в PostgreSQL.
6. Для региона и ОКПД2 раскройте «Расширенные фильтры ЕИС» и вставьте
   RSS-ссылку, созданную самим порталом. Она заменяет встроенные условия.

Поиск идёт только после нажатия. `RSS_LIVE_POLLING_ENABLED=false` остаётся
выключенным. Не используйте обход TLS, CAPTCHA, cookies или прокси.

Если Docker не доверяет сертификату ЕИС, используйте только официальные CA по
[`deploy/local-ca/README.md`](../deploy/local-ca/README.md), затем перезапустите
`web`, `queue` и `scheduler`.

Обычное обновление страницы, `docker compose restart` и пересоздание сервисов
не удаляют эти данные: PostgreSQL использует именованный Docker volume.
Команда с `down -v` или ручная очистка Docker volumes удаляет локальную базу
и должна применяться только когда это действительно нужно.

## Проверки

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web php artisan test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/pint --test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/phpstan analyse --memory-limit=1G
npm run build
```

`web`, `queue`, `scheduler`, PostgreSQL, Redis и Vite — части local runtime.
Их успешный запуск подтверждает только локальную среду. Он не включает
production, legal-документы, Telegram webhook, платежи или уведомления.
