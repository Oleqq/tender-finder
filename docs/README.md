# Документация Tender Finder

- [Демонстрация триала и покупки подписки](DEMO-CHECKOUT.md) — защищённый внешний сценарий без списаний.

Начните с [актуального состояния](CURRENT-STATE.md). В нём зафиксировано, что
работает сейчас, что сознательно выключено и в каком порядке развивать MVP.

| Документ | Назначение |
|---|---|
| [CURRENT-STATE](CURRENT-STATE.md) | Канонический статус MVP, пользовательский путь и ближайшие этапы. |
| [MAINTENANCE-2026-10-27](MAINTENANCE-2026-10-27.md) | Проверка обоих remote, планов коллеги, адаптивных форм и раскрытых мониторингов. |
| [UI-MAINTENANCE-2026-10-26](UI-MAINTENANCE-2026-10-26.md) | Общая вводная часть вкладок, темы, диалоги и предыдущая UI-приёмка. |
| [MAINTENANCE-2026-10-20](MAINTENANCE-2026-10-20.md) | Чекап Git, VPS, источников, интерфейса и сценария нового пользователя. |
| [PROGRESS](PROGRESS.md) | Хронология этапов, фактические выпуски и оставшаяся приёмка. |
| [NEXT-FEATURES](NEXT-FEATURES.md) | Приоритетный план следующих функций, критерии готовности и зависимости. |
| [PAYMENTS-NEXT-STAGE](PAYMENTS-NEXT-STAGE.md) | Текущий выбор платёжного канала, граница Telegram и план возможной внешней ЮKassa. |
| [SUBSCRIPTION-TARIFFS](SUBSCRIPTION-TARIFFS.md) | Цены Basic / Pro / Ultra на 1, 3 и 12 месяцев, скидки, состав тарифов и HTML-страницы предложения. |
| [PRO-ULTRA-FEATURE-ROADMAP](PRO-ULTRA-FEATURE-ROADMAP.md) | План восьми функций Pro / Ultra: статус PRO-01, сценарии заработка, зависимости и очередь реализации. |
| [PRICE-CATALOG-MATCHING](PRICE-CATALOG-MATCHING.md) | PRO-01: личный Excel-прайс, подбор тендеров, сравнение спецификации и ручное подтверждение позиций. |
| [COMPANY-REQUISITES](COMPANY-REQUISITES.md) | Публичная страница реквизитов ИП, исходный PDF и порядок обновления данных. |
| [TENDER-ANALYTICS-AND-SOURCES](TENDER-ANALYTICS-AND-SOURCES.md) | Краткая аналитика в карточке, выбор площадок мониторинга и множественный фильтр ленты. |
| [TENDER-ANALYTICS-TIERS](TENDER-ANALYTICS-TIERS.md) | Разделение аналитики Basic / Pro / Ultra, методика расчётов и серверный контроль доступа. |
| [DEVELOPER-PLAN-SWITCHING](DEVELOPER-PLAN-SWITCHING.md) | Переключение Basic / Pro / Ultra в Developer Mode, разрешения, сохранение выбора и сброс. |
| [YOOKASSA-INTEGRATION](YOOKASSA-INTEGRATION.md) | Реализованная серверная основа ЮKassa и оставшиеся шаги до тестового платежа. |
| [TELEGRAM-STARS-RUNBOOK](TELEGRAM-STARS-RUNBOOK.md) | Архив решения о выводе Stars из эксплуатации и порядок разбора неожиданного старого события. |
| [LEGAL-PRODUCTION-HANDOFF](LEGAL-PRODUCTION-HANDOFF.md) | Перечень документов и решений для юриста и бухгалтера перед публичным платным запуском; не готовые юридические тексты. |
| [TEKTORG-FABRIKANT-DISCOVERY](TEKTORG-FABRIKANT-DISCOVERY.md) | Проверка прямых источников ТЭК-Торг и Фабриканта и блокеры подключения. |
| [BIDZAAR-AND-REGION](BIDZAAR-AND-REGION.md) | Порядок получения доступа и включения Bidzaar, статус ЭТП РЕГИОН. |
| [EXTERNAL-SOURCE-DISCOVERY](EXTERNAL-SOURCE-DISCOVERY.md) | Что проверено по Росэлторгу и РТС-Тендер и какие разрешения ещё нужны. |
| [SUPPORT-WORKSPACE](SUPPORT-WORKSPACE.md) | Возможности поддержки, границы доступа и статус production-выпуска. |
| [VPS-DEPLOYMENT](VPS-DEPLOYMENT.md) | Фактический VPS-релиз, резервные копии и проверки production. |
| [MOBILE-LOAD-INCIDENT-2026-10-19](MOBILE-LOAD-INCIDENT-2026-10-19.md) | Факты по зависанию Mini App и порядок разбора повторного случая по HTTP-логам. |
| [INTEGRATION-STATUS](INTEGRATION-STATUS.md) | Архивный снимок интеграции веток от 2 сентября; не текущий статус. |
| [NEW-DEVELOPER](NEW-DEVELOPER.md) | Быстрое введение нового разработчика: границы, код и команды. |
| [LOCAL-RUNTIME](LOCAL-RUNTIME.md) | Запуск Docker и проверка мониторинга RosTender. |
| [LOCAL-TELEGRAM-BOT](LOCAL-TELEGRAM-BOT.md) | Локальная проверка Laravel Telegram webhook, очереди и Mini App. |
| [B2B-CENTER-SOURCE](B2B-CENTER-SOURCE.md) | Публичный каталог B2B-Center, настройки и ограничения. |
| [ETP-GPB-SOURCE](ETP-GPB-SOURCE.md) | Официальный RSS ЭТП ГПБ, настройки и проверка перед включением. |
| [RSS-MVP-SOURCE](RSS-MVP-SOURCE.md) | Архив выведенной из эксплуатации интеграции ЕИС. |
| [PARTICIPATION-AND-CALENDAR](PARTICIPATION-AND-CALENDAR.md) | Участие, чек-листы, календарь, экспорт и проверка. |
| [PARTICIPATION-LIFECYCLE](PARTICIPATION-LIFECYCLE.md) | Удаление и восстановление заявок, просроченные записи, статистика и остановка уведомлений. |
| [TEAM-WORKSPACES](TEAM-WORKSPACES.md) | Команды, роли, ответственные, шаблоны и напоминания по задачам. |
| [DATABASE](DATABASE.md) | Схема данных и правила хранения. |
| [DEPLOYMENT](DEPLOYMENT.md) | Исторический чек-лист VPS cutover; текущий порядок — в VPS-DEPLOYMENT. |
| [DESIGN-SYSTEM](DESIGN-SYSTEM.md) | Компоненты и правила UI. |
| [TESTING](TESTING.md) | Повторяемые проверки продукта и границы beta-приёмки. |
| [ADMIN-DASHBOARD](ADMIN-DASHBOARD.md) | Границы, формулы и проверка закрытой маркетинговой админки. |
| [LEGAL-DRAFTS](LEGAL-DRAFTS.md) | Материал для юридической проверки, не публичные документы. |
| [ТЗ бот тг+подписка](ТЗ%20бот%20тг%2Bподписка.md) | Исходные требования; не текущий статус разработки. |

Исторические планы, hand-off, отчёты прежних запусков и документация
неиспользуемого TenderGuru preview удалены из рабочей папки. История изменений
остаётся в Git; не используйте её как инструкцию к текущему MVP.
