# Локальный Docker-контур

Контур предназначен для разработки Mini App, мониторингов RosTender,
публичных источников СБЕР АСТ и Workspace.ru, Telegram-доставки и рабочих
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
`SBER_AST_*` и `WORKSPACE_RU_*`. СБЕР АСТ и Workspace.ru выключены в шаблоне
по умолчанию и читают только публичные данные без логина, cookies или
электронной подписи. Без включённого лицензионного gate список шаблонов
RosTender пуст и создать мониторинг нельзя. Приложение не обращается к
`zakupki.gov.ru`.

## Основная ручная проверка

1. Войдите через local subscriber и откройте «Мониторинги».
2. Выберите доступный шаблон RosTender, задайте ключевые и минус-слова.
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
