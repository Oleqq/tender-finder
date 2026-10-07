# Локальный Docker-контур

Контур предназначен для разработки Mini App, мониторингов RosTender,
публичных источников СБЕР АСТ, Workspace.ru и B2B-Center, Telegram-доставки и рабочих
процессов участия.

## Запуск

1. Создайте `deploy/local-runtime.env` из
   `deploy/local-runtime.example.env`; не добавляйте его в Git.
2. Выполните:

```powershell
docker compose -f compose.local.yml -f compose.local.dev.yml build
docker compose -f compose.local.yml -f compose.local.dev.yml --profile ops run --rm migrate
docker compose -f compose.local.yml -f compose.local.dev.yml up -d web queue scheduler vite
docker compose -f compose.local.yml -f compose.local.dev.yml ps
```

Приложение: `http://127.0.0.1:8080`.
Локальный full-access: `http://127.0.0.1:8080/local/mvp-subscriber`.

Для проверки реальных источников задайте разрешённые настройки `ROSTENDER_*`,
`SBER_AST_*`, `WORKSPACE_RU_*`, `B2B_CENTER_*`, а для частичных каталогов
`ROSELTORG_CATALOG_ENABLED`, `RTS_TENDER_CATALOG_ENABLED`,
`SBER_AST_CATALOG_ENABLED` и `PLATFORM_CATALOG_*`.
Публичные источники выключены в локальном шаблоне по умолчанию и читают
открытые данные без логина, cookies или электронной подписи. При включённом
публичном источнике мониторинг можно создать без шаблона RosTender; его
лицензионный gate ограничивает только этот дополнительный источник.
Приложение не обращается к
`zakupki.gov.ru`.

## Основная ручная проверка

1. Войдите через local subscriber и откройте «Мониторинги».
2. Включите публичный источник и задайте ключевые и минус-слова. Шаблон
   RosTender подключайте отдельно, когда он доступен.
3. Проверьте bounded preview, сохраните мониторинг и нажмите «Проверить сейчас».
4. Убедитесь, что карточки появляются в личной ленте и не видны другому
   пользователю без явного командного sharing.
5. Проверьте статусы, заметки, задачи, календарь и настройки уведомлений.

## Проверки

```powershell
make test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/pint --test
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T web vendor/bin/phpstan analyse --memory-limit=1G
docker compose -f compose.local.yml -f compose.local.dev.yml exec -T vite npm run build
```

Тесты используют отдельный Compose-сервис и SQLite `:memory:`. Не запускайте
тесты внутри `web`, подключённого к постоянной локальной PostgreSQL.
