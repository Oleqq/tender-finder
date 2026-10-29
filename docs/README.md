# Документация TenderFinder

Обновлено 29 октября 2026 года. Для знакомства достаточно
[текущего состояния](CURRENT-STATE.md) и [ближайших задач](NEXT-FEATURES.md).
Затем открывайте только документ нужной функции. Читать всю папку и архив
перед каждой задачей не требуется.

## Точки входа

| Документ | Когда нужен |
|---|---|
| [CURRENT-STATE](CURRENT-STATE.md) | Что выпущено, ограничения, платежи и источники на дату проверки. |
| [NEXT-FEATURES](NEXT-FEATURES.md) | Ближайшая приёмка, зависимости и отложенные направления. |
| [NEW-DEVELOPER](NEW-DEVELOPER.md) | Код, стек, локальный старт и правила разработки. |
| [PRO-ULTRA-FEATURE-ROADMAP](PRO-ULTRA-FEATURE-ROADMAP.md) | Сценарии и критерии восьми уже реализованных первых версий. |

## Функции Pro и Ultra

| Функция | Описание |
|---|---|
| [Мой прайс — PRO-01](PRICE-CATALOG-MATCHING.md) | Excel, подбор своих тендеров, спецификация, ручные сопоставления. |
| [Неучтённые расходы — PRO-02](COST-GAP-CHECK.md) | Документы, цитаты, проверка и перенос сумм в экономику. |
| [Вопросы заказчику — PRO-03](TENDER-QUESTIONS.md) | Основания, черновики, ручная отправка, ответы и история. |
| [Предложения — PRO-04](PROPOSAL-BUILDER.md) | Личная библиотека, шаблоны, сборка и TXT. |
| [Поставки победителю — ULTRA-01](WINNER-OPPORTUNITIES.md) | Ручная проверенная подборка, прайс, обращение и результат. |
| [Объединение закупок — ULTRA-02](PROCUREMENT-CONSOLIDATION.md) | Потребности контрактов, предложения и распределение затрат. |
| [Повторные закупки — ULTRA-03](REPEAT-TENDERS.md) | Подсказки, подтверждённые связи и уведомления внутри приложения. |
| [Контроль прибыли — ULTRA-04](EXECUTION-PROFIT-CONTROL.md) | План, факт, остаток и прогноз исполнения. |
| [Восстановление текста](TEXT-DRAFT-RECOVERY.md) | Временные правки в текущей вкладке и конфликты редакций. |

## Основной продукт и команды

- [Мониторинги, напоминания и обратная связь](FOLLOW-UP-FEATURES.md),
  [свежесть источников](SOURCE-FRESHNESS.md),
  [аналитика и площадки](TENDER-ANALYTICS-AND-SOURCES.md),
  [аналитика по тарифам](TENDER-ANALYTICS-TIERS.md).
- [Участие, документы и календарь](PARTICIPATION-AND-CALENDAR.md),
  [удаление и восстановление](PARTICIPATION-LIFECYCLE.md),
  [обсуждения и экономика](PARTICIPATION-COLLABORATION-ANALYTICS.md).
- [Команды и шаблоны](TEAM-WORKSPACES.md), [командная лента](TEAM-TENDER-FEED.md),
  [распределение и согласование](TEAM-WORKFLOW-AUTOMATION.md).
- [Поддержка](SUPPORT-WORKSPACE.md), [маркетинговая админка](ADMIN-DASHBOARD.md),
  [Telegram-уведомления](TELEGRAM-NOTIFICATION-FLOW.md).

## Доступ, оплата и юридическая подготовка

- [Тарифы и цены](SUBSCRIPTION-TARIFFS.md),
  [Developer Mode](DEVELOPER-PLAN-SWITCHING.md),
  [состояния доступа](TRIAL-TO-PAID-FLOW.md), [demo checkout](DEMO-CHECKOUT.md).
- [План настоящих платежей](PAYMENTS-NEXT-STAGE.md),
  [API-клиент ЮKassa](YOOKASSA-INTEGRATION.md),
  [обработка старых событий Stars](TELEGRAM-STARS-RUNBOOK.md).
- [Задание юристу](LEGAL-PRODUCTION-HANDOFF.md), [реквизиты](COMPANY-REQUISITES.md).
- Автономные страницы тарифов: [светлая](subscription-offer-preview.html) и
  [тёмная](subscription-offer-preview-dark.html). Это проверяемые HTML-артефакты;
  их исходные шаблоны — `resources/pricing`, цены — `SubscriptionCatalog`.

## Источники и исследования

Работающий на дату проверки набор указан только в [CURRENT-STATE](CURRENT-STATE.md).
Наличие адаптера или исследовательской заметки не означает, что источник включён.

- [Workspace.ru](WORKSPACE-RU-SOURCE.md), [B2B-Center](B2B-CENTER-SOURCE.md),
  [Bidzaar и ЭТП РЕГИОН](BIDZAAR-AND-REGION.md),
  [Сбер АСТ и его частичный каталог](SBER-AST-SOURCE.md).
- [RosTender](ROSTENDER-INTEGRATION.md) — работа отложена;
  [ЭТП ГПБ](ETP-GPB-SOURCE.md), [ТЭК-Торг и Фабрикант](TEKTORG-FABRIKANT-DISCOVERY.md)
  — не включены.
- [LLM-скоринг](LLM-TENDER-SCORING.md) — будущий этап, внешний LLM не подключён.

## Разработка и эксплуатация

[Локальный контур](LOCAL-RUNTIME.md) · [тестовый Telegram-бот](LOCAL-TELEGRAM-BOT.md) ·
[база данных](DATABASE.md) · [дизайн-система](DESIGN-SYSTEM.md) ·
[проверки](TESTING.md) · [VPS и выпуск](VPS-DEPLOYMENT.md).

## Архив и порядок обновления

[Архив](archive/README.md) содержит прежние отчёты, исходное ТЗ и снимки
старых сводок. Они сохранены для расследований и истории, не для выбора
следующей задачи. История правок также доступна в Git.

Менять текущий статус следует в `CURRENT-STATE`, очередь — в `NEXT-FEATURES`,
контракт функции — в её документе. Не добавлять одинаковые дневники релизов
в несколько сводок; для нового статуса достаточно ссылки на профильный файл.
Одноразовые подробные протоколы хранить отдельно от повседневных инструкций.
