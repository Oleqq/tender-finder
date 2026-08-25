# Tender Finder: локальная среда и синтетический RSS-тест

## Простое объяснение

Этот контур запускает на вашем компьютере четыре части будущего сервиса:

- `web` — сайт и API Laravel;
- `postgres` — основная база данных;
- `redis` — быстрая память для очереди и сессий;
- `queue` и `scheduler` — фоновые процессы: первый выполняет задания, второй
  будит их по расписанию.

Он нужен, чтобы безопасно проверить путь закупки без VPS и без реальных
пользователей. Компьютер должен быть включён: это среда разработки, не beta
для приглашённых людей.

## Что требуется один раз

1. Установить Docker Desktop для Windows и убедиться, что команда `docker
   compose version` отвечает.
2. Вручную создать `deploy/local-runtime.env` из
   `deploy/local-runtime.example.env`. Этот файл не отслеживается Git.
3. Самостоятельно сгенерировать `APP_KEY` и задать отдельные локальные значения
   PostgreSQL. Не передавать этот файл, ключи, пароли или Telegram-токены в чат
   и не коммитить их.

`compose.local.yml` слушает сайт только на `127.0.0.1:8080`: он не открывает
PostgreSQL или Redis в интернет и не содержит Caddy, домен или TLS.

## Первый локальный запуск

В корне проекта после ручной настройки локального файла выполнить:

```powershell
docker compose -f compose.local.yml build
docker compose -f compose.local.yml --profile ops run --rm migrate
docker compose -f compose.local.yml up -d web queue scheduler
docker compose -f compose.local.yml ps
```

Открыть `http://127.0.0.1:8080/health`. Ожидаемый ответ — `status: ok`.
`/ops/readiness` в локальном контуре намеренно не проверяется без отдельного
operator token.

## Безопасный RSS-сценарий без сети ЕИС

Все команды ниже используют встроенные XML-файлы. Они не делают запрос в ЕИС,
не включают `RSS_LIVE_POLLING_ENABLED` и не создают настоящего Telegram-
пользователя.

```powershell
docker compose -f compose.local.yml exec web php artisan tenders:seed-local-scenario
docker compose -f compose.local.yml exec web php artisan tenders:import-fixture initial
docker compose -f compose.local.yml exec web php artisan tenders:import-fixture next
docker compose -f compose.local.yml exec web php artisan tenders:show-local-matches
```

Результат понятен так:

1. `seed-local-scenario` создаёт пример одного мониторинга: «поддержка сайтов».
2. `initial` имитирует первый опрос. Запись попадает в базу, но не вызывает
   уведомление — это правило first-poll silence, чтобы не засыпать нового
   пользователя старыми закупками.
3. `next` добавляет только новую тестовую закупку. Очередь находит совпадение.
4. `show-local-matches` выводит будущую карточку в консоль: мониторинг,
   заголовок, номер реестра и причину попадания.

В настоящем Mini App тот же `TenderQueryMatch` попадает в защищённый экран
`/tenders` только владельцу соответствующего мониторинга. Anonymous browser
preview остаётся demo и не получает серверные данные.

## Как данные становятся карточкой

```text
XML fixture
   -> SourceFeedItem (дедупликация по ссылке)
   -> Tender (название, ссылка, номер реестра, описание)
   -> TenderQueryMatch (совпадение и его причины)
   -> защищённая карточка /tenders для владельца мониторинга
```

RSS текущей beta не извлекает из тестового XML заказчика, НМЦК или срок подачи,
поэтому карточка честно пишет «не указано», если поля отсутствуют. Реальные
поля и допустимые EIS RSS URL будут добавляться только после `SRC-00`.

## Чего локальный контур не делает

- не публикует сайт и не заменяет Vercel demo;
- не меняет menu button или webhook Telegram;
- не включает trial: legal publication gate остаётся закрытым;
- не запускает live RSS и не обращается к ЕИС;
- не является резервируемым и постоянно доступным production-сервером.

После локальной проверки следующий внешний этап — VPS и managed PostgreSQL /
Redis в РФ. Только там выполняются container smoke-test, настоящий Telegram
smoke-test и `SRC-00`.
