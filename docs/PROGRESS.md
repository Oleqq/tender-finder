# Tender Finder: журнал прогресса

Этот файл ведётся от первого лица проекта. После каждой завершённой задачи обновляйте дату, статус, ссылки на изменения и краткий результат. Не записывайте сюда токены, пароли, персональные данные и платёжные идентификаторы.

## Сводка

| Поле | Значение |
|---|---|
| Текущий этап | Server foundation: DB, identity/access, webhook и Basic tender core подготовлены к VPS activation |
| Статус MVP | Код и тесты серверных этапов готовы локально; production всё ещё работает как Vercel demo, пока не появятся VPS, managed PostgreSQL/Redis, public legal URLs и Telegram smoke-test |
| Стек | PHP 8.3 + Laravel, React + TypeScript |
| Основной интерфейс клиента | Telegram Mini App на домене проекта |
| Админка | Ролевые экраны внутри того же Telegram Mini App; доступны только `super_admin` после server-side проверки |
| Главный источник MVP | Публичный RSS расширенного поиска ЕИС, закрытая бета |
| Источник после MVP | СОИ ЕИС или лицензированный поставщик данных |
| Платежи MVP | Telegram Stars для цифрового доступа внутри Telegram; цены и модель продления требуют продуктового решения |
| Последнее обновление | 2026-08-25 |

## Доска задач

Обозначения: `TODO` - не начато, `IN PROGRESS` - в работе, `BLOCKED` - есть внешний блокер, `DONE` - завершено и проверено.

| ID | Задача | Этап | Статус | Критерий готовности | Блокер |
|---|---|---|---|---|---|
| DOC-01 | Изучить исходное ТЗ и подготовить рабочие документы | Подготовка | DONE | Созданы понятный и технический планы | Нет |
| DOC-03 | Подготовить понятный путеводитель по проекту и hand-off | Документация | DONE | Есть WordPress → Laravel карта, диаграммы Docker/потоков и prompt следующего чата | Нет |
| DEC-01 | Подтвердить Laravel + React как стек MVP | Подготовка | DONE | Решение зафиксировано в документации | Нет |
| DEC-02 | Зафиксировать Mini App как основной интерфейс, а бота как канал и вход | Подготовка | DONE | Архитектура отражена в документации | Нет |
| ADM-01 | Спроектировать встроенный admin-раздел, роли и внутренний доступ | Подготовка | DONE | Две роли, access model, audit и screen map отражены в документации | Нет |
| DB-01 | Спроектировать и реализовать нормализованную schema foundation | Server foundation | DONE | Migrations, `DATABASE.md`, индексы и SQLite tests готовы; production migration ожидает managed PostgreSQL | Managed PostgreSQL/VPS activation |
| EXT-01 | Получить документацию и доступный способ интеграции ЕИС через СОИ | Подготовка | TODO | Есть тестовый запрос и описание лимитов | Заказчик/ЕИС |
| EXT-02 | Зафиксировать цены/лимиты и правила продления в Telegram Stars | Подготовка | TODO | Подтверждённая матрица планов и тестовые сценарии оплаты/возврата | Заказчик |
| EXT-03 | Подготовить оферту и политику конфиденциальности | Подготовка | TODO | Стабильные публичные ссылки | Заказчик/юрист |
| INF-01 | Создать Laravel + React-проект и Docker-окружение | Этап 0 | IN PROGRESS | Готовы image, Compose web/queue/scheduler/Caddy и VPS runbook; нужен внешний container smoke-test | VPS, managed PostgreSQL/Redis, домен |
| INF-02 | Настроить CI, `.env.example`, health check и логи | Этап 0 | DONE | Проверки настроены для CI, `/health` возвращает JSON, логи структурированы | Нет |
| INF-03 | Подготовить Vercel production-контур | Переход к этапу 1 | DONE | Production `GET /health` возвращает ожидаемый JSON | Managed PostgreSQL и Redis нужны перед включением пользовательских сценариев |
| WEB-01 | Реализовать React-каркас Mini App и проверку Telegram `initData` | Этап 1 | IN PROGRESS | Design system, SPA, signed session endpoint и secure role bootstrap готовы; нужен production smoke-test | VPS, managed PostgreSQL/Redis, Telegram secrets |
| WEB-02 | Собрать preview, paywall и access-aware UI на demo-данных | Этап 1 | DONE | Все состояния до/после оплаты, loading/error/empty и reduced motion проверены | Нет |
| WEB-03 | Уточнить data-first дизайн и расширить component library | Этап 1 | DONE | Новые элементы собраны из tokens, blur уменьшен, catalogue актуален | Нет |
| BOT-01 | Подключить Telegram webhook и `/start`, `/help` | Этап 1 | IN PROGRESS | Secret check, deduplication, queue job и reply logic готовы; нужны HTTPS webhook и test chat | VPS, Telegram secrets, managed Redis |
| BOT-02 | Реализовать согласия, trial 72 часа и напоминания | Этап 1 | IN PROGRESS | Append-only consent и one-time 72h trial готовы; reminders и production activation ждут legal URLs | Публичные ссылки на оферту и политику конфиденциальности |
| QRY-01 | Реализовать мастер создания и управления запросами | Этап 2 | IN PROGRESS | Authenticated server API/UI для create/edit/pause/resume/freeze готов; production activation и расширенная форма впереди | VPS activation |
| SRC-00 | Подготовить и вручную проверить RSS-ленты ЕИС для тестовых сценариев | Этап 2 | TODO | URL лент, поля и задержка зафиксированы в тестовом наборе | Нет |
| SRC-01 | Реализовать `EisRssSource`, polling и дедупликацию | Этап 2 | IN PROGRESS | Fixture parser/import, URL allowlist, errors и first-poll silence проверены; live fetch выключен до SRC-00 | SRC-00 |
| SRC-02 | Реализовать мониторинг RSS-источника и лимит 100 уникальных лент | Этап 2 | IN PROGRESS | Schema source runs и code limit 100/10m/global throttle готовы; real telemetry/admin screen ждут live source | SRC-00, VPS |
| ADM-02 | Реализовать super-admin shell, policies и аудит внутри Mini App | Этап 3 | TODO | Только super-admin видит и проходит admin endpoints, действия аудируются | Identity/access domain |
| ADM-04 | Реализовать admin read models: Overview, Live Ops, Users, Sources | Этап 3 | TODO | Метрики имеют server-side определение и реальные states | ADM-02, core data |
| ADM-05 | Реализовать кампании и Telegram-алерты | Этап 3 | TODO | Preview, сегмент, limits, delivery journal и отмена протестированы | ADM-02, NTF-01 |
| ADM-03 | Реализовать бесплатный внутренний доступ | Этап 3 | TODO | Доступ выдаётся/отзывается без подмены платежей | ADM-02 |
| MAT-01 | Реализовать базовый матчинг | Этап 3 | DONE | Deterministic keyword/minus/region/budget/deadline reasons покрыты tests | Нет |
| NTF-01 | Реализовать уведомления и антиспам | Этап 3 | IN PROGRESS | Delivery ledger, idempotency и 20/hour → digest queue готовы; Telegram delivery ждёт real bot/runtime smoke-test | VPS, Telegram secrets |
| PAY-01 | Реализовать планы, paywall и Telegram Stars | Этап 4 | TODO | Успешная тестовая Stars-оплата активирует доступ ровно один раз | EXT-02, PostgreSQL/Redis |
| PAY-02 | Реализовать lifecycle, возвраты и биллинг-уведомления | Этап 4 | TODO | Статусы, refund и повтор update проверены | PAY-01 |
| PRO-01 | Спроектировать evaluation и feedback для персонального scoring | Future PRO | TODO | Есть измеримая модель качества и privacy/cost gate | Данные Basic-плана |
| OPS-01 | Настроить backup, мониторинг и алерты | Этап 5 | TODO | Восстановление БД проверено | VPS |
| QA-01 | Пройти тесты и чек-лист приёмки MVP | Этап 5 | TODO | Все обязательные проверки пройдены | Нет |

## Журнал изменений

### 2026-08-22 - подготовка

- Изучено исходное ТЗ в Markdown-формате.
- Выбран стек MVP: PHP 8.3 + Laravel, PostgreSQL и Redis.
- Сформированы понятный план, техническая декомпозиция и этот журнал.
- Исходное ТЗ не изменялось.
- Внешние зависимости до начала интеграций: доступ к ЕИС, тестовая ЮKassa, юридические документы, VPS и Telegram-бот.

### 2026-08-22 - изменение архитектуры интерфейсов

- React + TypeScript добавлены для клиентского Telegram Mini App и админ-панели.
- Mini App определён как основной интерфейс клиента; Telegram-бот остаётся входом, каналом уведомлений и резервным способом работы.
- Админ-панель перенесена в MVP: управление пользователями, подписками, платежами, источниками и рассылками.
- Бесплатный доступ владельца будет отдельным контролируемым типом доступа с аудитом, а не обходом платёжной логики.

### 2026-08-22 - источник ЕИС для закрытого MVP

- Изучен MIT-репозиторий `free-zakupki-gov-ru-monitor` как технический референс RSS-поллинга.
- Для закрытой беты выбран `EisRssSource`: публичные RSS-ленты расширенного поиска ЕИС, server-side polling, нормализация и дедупликация.
- Код Chrome-расширения не переносится; Laravel-реализация будет собственной.
- Установлен предел: до 100 уникальных активных RSS-лент, опрос по умолчанию раз в 10 минут с общим rate limit.
- СОИ ЕИС остаётся целевым источником для масштабирования после MVP.

### 2026-08-22 - этап 0: подготовка проекта

- Задачи: `INF-01`, `INF-02`.
- Результат: создан Laravel 12-проект с Inertia, React, TypeScript и Vite; добавлены базовая страница Tender Finder, stateless `GET /health`, структурированные JSON-логи, конфигурация PostgreSQL/Redis и роль `admin` в модели пользователя.
- Миграции ограничены пользователями и ролью; таблицы тендеров, RSS, подписок и платежей не создавались. Telegram, платежи и Mini App-авторизация не реализовывались.
- Проверка: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run lint`, `npm run format:check`, `npm run build`, а также миграция в изолированной тестовой БД и запрос `GET /health`.
- Изменения: будут зафиксированы отдельным коммитом этапа 0.
- Следующее действие: `WEB-01` — React-каркас Mini App и серверная проверка Telegram `initData`.
- Блокеры: локальная PostgreSQL требует пароля в `.env`; для реальных интеграций также понадобятся Telegram token и домен.

### 2026-08-22 - локальный Redis

- Результат: Redis установлен в Windows и запущен на `127.0.0.1:6379`; Laravel записал и прочитал контрольное значение через настроенный Redis driver.
- Проверка: `Test-NetConnection 127.0.0.1 -Port 6379`, `php artisan tinker --execute="Cache::store('redis')->put(...); ..."`, `GET /` и `GET /health` через Herd вернули `HTTP 200`.
- Блокеры: пароль для локальной PostgreSQL ещё не указан; порт Redis после перезагрузки следует проверить и при необходимости запустить Redis снова.

### 2026-08-24 - план этапа 1: Telegram и онбординг

- Задачи: `WEB-01`, `BOT-01`, `BOT-02`.
- План: добавить Telegram-идентификаторы, согласия и однократный trial; проверить `initData` на сервере и выдать безопасную браузерную сессию; реализовать webhook и команды `/start`, `/help`; затем добавить Inertia-экраны онбординга, согласий, пустого списка тендеров и профиля. После этого реализовать идемпотентные напоминания за 24 и 3 часа до завершения trial и покрыть поддельные данные, повторный trial и полный onboarding-тестами.
- Изменения: прикладной код и конфигурация не менялись.
- Следующее действие: получить обязательные внешние данные и начать реализацию в указанном порядке.
- Блокеры: Telegram Bot Token; публичный HTTPS-домен для webhook и Mini App; публичные ссылки на оферту и политику конфиденциальности; тестовый Telegram-аккаунт или чат. Для проверки реальных миграций также нужен пароль локальной PostgreSQL в `.env`.

### 2026-08-24 - production-контур перед Telegram-этапом

- Задача: `INF-03`.
- Цель: получить стабильный HTTPS-адрес, на котором будут работать Telegram Mini App и webhook. Без работающего публичного HTTPS нельзя безопасно проверить `initData`, команды бота и пользовательский онбординг.
- Результат: личное и исходное Git-хранилища синхронизированы при push, Vercel использует личное хранилище; production-переменные хранятся вне Git, а для необработанных Laravel-исключений добавлено безопасное сообщение в системный log stream без заголовков запроса и секретов. Имя production log channel нормализуется перед запуском Laravel.
- Текущая проверка: deployment Vercel создаётся успешно; на публичном HTTPS-адресе `GET /health` возвращает ожидаемый JSON c `HTTP 200`.
- Следующее действие: подключить managed PostgreSQL и Redis. После этого можно переходить к серверной проверке Telegram `initData`, webhook и экрану онбординга.
- Блокеры этапа 1: публичные ссылки на оферту и политику конфиденциальности; managed PostgreSQL и Redis для постоянных сессий, очередей и trial; пароль локальной PostgreSQL для выполнения реальных локальных миграций.

### 2026-08-24 - стабилизация Vercel health check

- Задача: `INF-03`.
- Результат: production locale-настройки исправлены, deployment повторно выполнен, публичный `GET /health` отвечает ожидаемым JSON с `HTTP 200`.
- Проверка: `curl.exe --silent --show-error https://tender-finder-navy.vercel.app/health`.
- Изменения: обновлён журнал прогресса; значения production-переменных остаются вне Git.
- Следующее действие: подключить managed PostgreSQL и Redis с production-учётными данными, затем начать `WEB-01`, `BOT-01` и `BOT-02`.
- Блокеры: публичные ссылки на оферту и политику конфиденциальности; managed PostgreSQL и Redis. Локальный Redis сейчас не запущен, а пароль локальной PostgreSQL не указан.

### 2026-08-24 - выравнивание локального и production-интерфейсов

- Задача: `INF-03`.
- Результат: Laravel в production теперь доверяет HTTPS-схеме reverse proxy, поэтому ссылки на собранные фронтенд-ассеты формируются с `https`; production `APP_URL` задан как публичный HTTPS-адрес. Локальный Redis запущен для проверки web-сессий через Herd.
- Проверка: локальный `GET /` возвращает `HTTP 200`; `php artisan test` и `npm run build` проходят успешно. После deployment публичная корневая страница должна загрузить JS и CSS по HTTPS.
- Изменения: добавлена production-настройка trusted proxies и обновлён журнал прогресса; секреты и локальный `.env` не изменялись.
- Следующее действие: проверить production-корневую страницу после deployment, затем начать локальную разработку Inertia-экранов этапа 1.
- Блокеры: managed PostgreSQL и Redis остаются обязательными до включения реальных Telegram-сессий, trial и очередей; также нужны публичные юридические ссылки.

### 2026-08-24 - совместимость CI с PHP 8.3

- Задача: `INF-02`.
- Результат: Composer теперь разрешает зависимости с платформой PHP 8.3.0; lock-файл больше не содержит Symfony-пакеты, требующие PHP 8.4. Это восстанавливает совместимость с версией PHP, используемой в CI.
- Проверка: `composer validate --strict`, `composer check-platform-reqs`, `php artisan test`, Pint, PHPStan, ESLint, Prettier и production-сборка прошли успешно.
- Изменения: обновлены `composer.json` и `composer.lock`; PHP 8.3 остаётся минимально поддерживаемой версией проекта.
- Следующее действие: дождаться успешного CI после push, затем проверить production-корневую страницу и начать локальную разработку Inertia-экранов этапа 1.
- Блокеры: managed PostgreSQL и Redis остаются обязательными до включения реальных Telegram-сессий, trial и очередей; также нужны публичные юридические ссылки.

### 2026-08-24 - порядок frontend-сборки в CI

- Задача: `INF-02`.
- Результат: frontend-сборка Vite в CI выполняется до Laravel feature-тестов. Чистый runner теперь получает `public/build/manifest.json` до первого рендеринга Inertia-страницы.
- Проверка: локальный набор CI запускается в том же порядке: установка Node-зависимостей, production-сборка, затем PHP-тесты и статические проверки.
- Изменения: обновлён порядок шагов в CI и журнал прогресса.
- Следующее действие: подтвердить успешный CI после push, затем завершить проверку production-корневой страницы.
- Блокеры: managed PostgreSQL и Redis остаются обязательными до включения реальных Telegram-сессий, trial и очередей; также нужны публичные юридические ссылки.

### 2026-08-24 - foundation Mini App и demo-экраны

- Задача: `WEB-01` (клиентская часть).
- Результат: реализованы оригинальная mobile-first дизайн-система с light/dark-токенами, glass-поверхностями, safe-area, reduced motion и Telegram theme parameters. Добавлены Inertia-экраны запуска, onboarding, согласий, пустых «Моих тендеров», профиля и demo-dashboard, а также общий AppShell, нижняя навигация, cards, controls, sheet, toast, skeleton и статусные компоненты.
- Проверка: production Vite-сборка проходит; локально в мобильном viewport пройдены Inertia-переходы onboarding/consents/dashboard и интерактивные demo-состояния без console errors. Feature-тесты покрывают ответы всех новых Inertia-маршрутов.
- Изменения: локальный рабочий блок, без серверных Telegram-данных, RSS, платежей или админки.
- Следующее действие: после deployment повторно проверить production `GET /` — Vite-ссылки должны быть HTTPS; затем дождаться managed PostgreSQL/Redis и юридических ссылок для серверной части `WEB-01`.
- Блокеры: managed PostgreSQL и Redis; публичные ссылки на оферту и политику конфиденциальности. Клиентские данные Telegram не считаются доверенными до будущей server-side проверки `initData`.

### 2026-08-24 - HTTPS-корень Vite в production

- Задача: `INF-03`.
- Результат: для web-запросов production Laravel принудительно формирует Vite-asset URLs от HTTPS-схемы и публичного host запроса. Для Vercel добавлен прямой Vite asset resolver, чтобы отдельный HTTP asset root в configuration cache не влиял на HTML.
- Проверка: локальные PHP, статические и frontend-проверки проходят; публичные `GET /` и `GET /health` после deployment подтверждены. Корневая HTML-страница содержит только HTTPS-ссылки на `/build/assets/`, health check возвращает HTTP 200.
- Изменения: секреты и Vercel environment variables не изменялись.
- Следующее действие: подтвердить `GET /` с HTTPS-ссылками на `/build/assets/` и `GET /health` с HTTP 200.
- Блокеры: нет для проверки URL; managed PostgreSQL/Redis и юридические ссылки остаются внешними блокерами серверного Telegram-этапа.

### 2026-08-24 - первый запуск Mini App из Telegram

- Задача: `WEB-01` (ручной demo-запуск).
- Результат: для бота настроена стандартная menu button «Открыть Tender Finder», ведущая на production Mini App. Пользователь может открыть чат с ботом, нажать «Старт» и запустить интерфейс кнопкой меню без webhook и постоянной БД.
- Проверка: Telegram Bot API подтвердил тип `web_app`, текст кнопки и HTTPS URL production.
- Изменения: внешняя настройка бота; токены, ключи и другие учётные данные не записывались в репозиторий или документацию.
- Следующее действие: после подготовки managed PostgreSQL/Redis и юридических ссылок реализовать серверные `/start`, `/help`, webhook, проверку `initData` и сохранение согласий.
- Блокеры: для demo-запуска нет; для настоящего пользовательского сценария остаются managed PostgreSQL/Redis и публичные юридические ссылки.

### 2026-08-24 - B2C roadmap, Telegram Stars и embedded super-admin

- Задачи: `DOC-02`, `WEB-02`, `WEB-03`, `ADM-02`, `PAY-01`, `PRO-01`.
- Результат: рабочие документы приведены к актуальной модели: две роли (`subscriber` и `super_admin`), отдельно планы и entitlement, первый платёжный поток через Telegram Stars, а административные разделы — внутри Mini App. Описаны preview/trial/paywall путь, Basic/будущий PRO, кампании от бота, аудит, telemetry и последовательность реализации LLM-scoring.
- Дизайн: зафиксирован data-first подход с ограниченным glass budget; составлен каталог уже созданных и следующих UI-компонентов, экранная карта и правила motion/loading/error states. Визуальные референсы используются только как ориентир композиции, не как готовые assets или копируемый интерфейс.
- Проверка: обновлены `README.md`, `docs/README.md`, `docs/TECHNICAL-PLAN.md`; добавлены `docs/PRODUCT-ROADMAP.md` и `docs/DESIGN-SYSTEM.md`. Исходное ТЗ не изменялось.
- Следующее действие: `WEB-03` и `WEB-02` — переработать текущий demo-dashboard в более плотный data-first UI, собрать primitives, preview/paywall и первые super-admin demo-states.
- Блокеры: для frontend demo-работы нет. Серверные Telegram-сессии, trial, Stars, данные админ-экранов и рассылки требуют managed PostgreSQL/Redis, публичных legal URLs и согласованных планов/цен.

### 2026-08-24 - data-first preview/paywall foundation

- Задачи: `WEB-02`, `WEB-03`.
- Результат: UI смещён от избыточного translucent/gradient-паттерна к более контрастным surface-слоям с ограниченным blur. Добавлены повторно используемые `InlineAlert`, `DataRow`, `ProgressBar`, `AccessGate` и `PlanCard`; реализован demo-экран планов и доступа с честно помеченным будущим Telegram Stars checkout. Профиль связывает текущий demo с этим экраном.
- Проверка: добавлен Inertia feature-test маршрута `/plans`; полный набор проверок будет выполнен перед коммитом.
- Изменения: frontend/demo и документация; реальные subscription, invoice, Telegram-данные и admin-доступ не создавались.
- Следующее действие: завершить локальные проверки, затем продолжить `WEB-03` с chart/skeleton/form primitives и подготовить role-aware super-admin shell после появления identity domain.
- Блокеры: нет для UI. Для real checkout, entitlement и role-aware routing требуются managed PostgreSQL/Redis и server-side `initData`.

### 2026-08-24 - WEB-03: формы, states и Operations demo

- Задачи: `WEB-03`; frontend-часть `WEB-02` и demo-основа `ADM-02`.
- Результат: добавлены `MetricTrend`, data-shape skeletons для метрик, строк и карточек, select/combobox, multi-select, date range, money range, validation, error/retry/offline states. Экран «Тендеры» показывает их как явно маркированные client-side образцы; фильтры не сохраняются и не отправляются на сервер. На экране планов появилось сравнение Basic/будущего PRO без цен, неподтверждённых обещаний или преждевременного LLM-функционала.
- Admin demo: внутри существующего `AppShell` добавлен role-aware вариант навигации и read-only Operations demo с Overview/Live Ops, loading-shapes и безопасными примерными значениями. Экран прямо сообщает, что это не доступ администратора, не telemetry и не источник персональных данных; реальная роль остаётся только server-side после проверки Telegram `initData`.
- Проверка: `npm run typecheck`, `npm run lint` и `npm run build` прошли. В браузере на mobile viewport 390×844 проверены `/tenders` (форма, validation alert, loading/error/offline) и `/operations-demo` (Overview, Live Ops, loading); прокрутка, safe bottom navigation и reduced-motion CSS сохранены.
- Изменения: frontend/demo, feature route test и актуализация дизайн-каталога; Telegram auth, persistence, payments, Stars invoice, webhook и реальные admin read models не добавлялись.
- Следующее действие: завершить полный набор проверок, затем продолжить `WEB-02` с access/payment states только на demo-данных либо начать серверный этап после managed PostgreSQL/Redis и public legal URLs.
- Блокеры: для UI нет. Реальная роль, policies, сессии, telemetry, queue states и billing требуют managed PostgreSQL/Redis, server-side `initData` и публичные legal URLs.

### 2026-08-24 - WEB-02: demo access и checkout states

- Задача: `WEB-02`.
- Результат: в client-side demo добавлена отдельная модель access states `preview`, `trialing`, `active`, `expired`; она используется на экранах планов и профиля, но не является ролью и не сохраняется. `/plans` получил demo checkout с preview, loading, recoverable error/retry и примером активного Basic. Во всех состояниях явно указано, что invoice, trial и entitlement не создаются.
- Процесс: технический план дополнен границей demo commerce: UI до backend-этапа существует только в памяти React; настоящая цена, invoice, payment event, entitlement и retry остаются исключительно server-side responsibility Laravel.
- Проверка: `npm run typecheck` и `npm run lint` прошли до форматирования; `npm run build` прошёл после изменений. В mobile browser viewport 390×844 вручную проверены `/plans` (checkout preview/loading/error/retry) и `/profile` (Basic example); dialog и нижняя навигация не перекрывают действия.
- Изменения: только frontend/demo и документация. Telegram auth, Stars invoice, persistence, trial, payment event, webhook и server access policy не добавлялись.
- Следующее действие: ждать managed PostgreSQL/Redis и public legal URLs для server-side `WEB-01` / `BOT-01` / `BOT-02`; до этого допустимы только отдельные UI/documentation improvements без имитации backend-эффектов.
- Блокеры: managed PostgreSQL, Redis, public offer/privacy URLs, зафиксированные Basic limits/prices и Telegram test account нужны до реального trial, checkout и ролей.

### 2026-08-25 - server foundation, красивая БД и безопасный Basic core

- Задачи: `DB-01`, `INF-01`, серверная часть `WEB-01`, `BOT-01`, `BOT-02`, `QRY-01`, `SRC-01`, `SRC-02`, `MAT-01`, `NTF-01`.
- Простое объяснение: проект получил «настоящий фундамент», но не был тайно включён для людей. Теперь сервер умеет отличать проверенный Telegram-вход от browser preview, помнить согласия и один trial, хранить мониторинги и безопасно разбирать учебные RSS-ленты. В production пока по-прежнему только demo, поэтому никто не получил trial, уведомление или admin-доступ случайно.
- Результат: добавлены нормализованные migrations для identity/access/query/source/tender/delivery, отдельные роли `subscriber`/`super_admin`, verified `POST /telegram/session`, append-only consents, 72h trial, protected webhook с дедупликацией, queue jobs `/start`/`/help`, query API/UI, RSS fixture importer, deterministic matching и delivery ledger с anti-spam limit. Созданы `DATABASE.md`, VPS Compose/Caddy runtime и новый deployment runbook.
- Безопасность: raw initData/webhook payload, Telegram secrets, owner ID и cookies не сохраняются. Роль назначается только после HMAC verification. `GET /health` не зависит от сервисов; `GET /ops/readiness` требует Bearer token и не раскрывает connection details. Live RSS выключен конфигурацией до `SRC-00`.
- Проверка: добавлены тесты forged/expired initData, owner/non-owner role, duplicate consent/trial/webhook, trial duration, query limit, RSS first-poll silence/dedup/invalid source и explainable matching. Перед commit/push прошли `npm run build`, `php artisan test` (22 passed, 145 assertions), Pint, PHPStan, ESLint и Prettier. В in-app browser на 390×844 проверен anonymous fallback `/queries` → onboarding без console errors; это подтверждает, что browser preview не обходит server auth. Docker отсутствует на локальной машине, поэтому container smoke-test остаётся задачей VPS.
- Следующее действие: получить VPS, managed PostgreSQL/Redis и public offer/privacy URLs; сделать container smoke-test, production migration и только затем переключить Telegram menu/webhook. Отдельно завершить reminders, RSS URL validation `SRC-00`, real telemetry/admin и Stars.
- Блокеры: доступ к VPS, production domain, managed PostgreSQL/Redis, Telegram secrets/test chat, public legal URLs и вручную подтверждённые EIS RSS URLs/terms. Значения не хранятся в Git.

### 2026-08-25 - понятная карта проекта и hand-off следующего этапа

- Задача: `DOC-03`.
- Простое объяснение: появился отдельный путеводитель для разработчика с WordPress-бэкграундом. Он без скрытых терминов объясняет, чем Laravel отличается от CMS, где лежит интерфейс и серверный код, как данные проходят через приложение и почему Docker не является «ещё одной базой данных». В нём есть наглядные схемы пути пользователя, RSS и будущего VPS.
- Результат: добавлены `BEGINNER-GUIDE.md` с аналогиями WordPress → Laravel, картой директорий, диаграммами Mermaid, объяснением PostgreSQL/Redis/Docker, списком проверок и глоссарием; добавлен `NEXT-CHAT-HANDOFF.md` с копируемым контекстом, правилами безопасности, известными внешними блокерами и рекомендованной очередью следующих этапов. Рабочая карта `docs/README.md` теперь ссылается на оба файла.
- Техническая граница: документация не включает секреты, owner ID, значения `.env` или персональные данные и не меняет код, runtime или production. Она прямо отличает подготовленную серверную функциональность от включённой на Vercel demo.
- Проверка: ссылки и Mermaid-блоки просмотрены в Markdown. Перед публикацией прошли `npm run build`, `php artisan test` (22 passed, 145 assertions), Pint, PHPStan, ESLint и Prettier. Документационный commit `5886d3f` отправлен в оба настроенных `origin` URL.
- Следующее действие: после получения VPS, managed PostgreSQL/Redis и legal URLs перейти к container smoke-test и реальному Telegram smoke-test по `DEPLOYMENT.md`; до этого можно улучшать только безопасные UI/docs или тестируемые offline части.
- Блокеры: те же внешние production prerequisites — VPS/domain, managed PostgreSQL/Redis, public offer/privacy URLs, secret store и test Telegram account.

## Шаблон новой записи

Копируйте этот блок под журналом после завершения значимой задачи.

```md
### YYYY-MM-DD - краткое название

- Задача: `ID`.
- Результат: что теперь работает.
- Проверка: команды, тесты или сценарий, которым это подтверждено.
- Изменения: ссылка на pull request или хеш коммита.
- Следующее действие: конкретная задача.
- Блокеры: нет / описание и ответственный.
```

## Правило готовности задачи

Задача считается `DONE`, только если код проверен, миграции и конфигурация документированы, а пользовательский сценарий не сломан. Если работа ждёт доступ, решение заказчика или внешнюю систему, ставьте `BLOCKED` и указывайте, что именно нужно получить.
