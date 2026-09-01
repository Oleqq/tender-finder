# Дизайн-система Mini App

## Характер интерфейса

Tender Finder — не абстрактный «glass dashboard», а рабочий инструмент для
решений под ограниченное время. Визуальный язык: тёмные/светлые Telegram-темы,
слои «tender radar», точные линии, статусные сигналы и плотные, легко
сканируемые данные. Liquid glass используется дозированно как средство
фокуса, а не как фон для каждой карточки.

Принцип «glass budget»: один акцентный hero или paywall-layer плюс постоянная
навигация; списки тендеров, таблицы и метрики получают преимущественно
непрозрачные surface-слои. Blur низкий и отключаемый при reduced motion /
слабом устройстве. Случайные сияющие орбы, избыточные градиенты и одинаковые
крупные плашки не являются частью системы.

Полезные референсы по композиции, а не для копирования: [mobile analytics
dashboard](https://dribbble.com/shots/26102606-NexusDash-SaaS-Business-Analytics-Dashboard-Mobile-App),
[mobile paywalls](https://dribbble.com/shots/25198822-Paywall-Designs-for-Mobile-Apps-Dark-Mode),
[admin dashboard concept](https://www.behance.net/gallery/192743839/Super-Admin-Dashboard-App-UI-Concept)
и официальные [Telegram Mini Apps](https://core.telegram.org/bots/webapps).

## Foundation

Уже есть: CSS variables с light/dark палитрами, привязка Telegram theme
parameters, типографика, spacing, radius, borders, shadows, status colors,
safe-area, touch targets и reduced-motion. Новые элементы используют только
семантические токены (`surface`, `text`, `accent`, `success`, `warning`,
`danger`), а не локальные hex-значения.

| Категория | Правило |
|---|---|
| Layout | mobile-first, сетка 4 pt, viewport и safe-area Telegram |
| Data density | заголовок + важный показатель + вторичная строка; детализация по tap |
| Contrast | статус никогда не выражается только цветом; у него есть label/icon |
| Touch | интерактивная зона не меньше 44×44 CSS px |
| Motion | 160–220 ms, transform/opacity, максимум 3–5 stagger-элементов |
| Loading | skeleton повторяет форму будущих данных; не играет бесконечно без запроса |
| Accessibility | focus, aria-label, понятная ошибка и reduced motion обязательны |

## Каталог компонентов

| Группа | Готово | Ближайший backlog |
|---|---|---|
| Shell | AppShell, top bar, bottom navigation, Telegram setup, role-aware nav variant | server-authorized admin entry point, pull-to-refresh |
| Surfaces | glass card, status/badge, metric card, dense data row, inline alert, progress | divider, tooltip, contextual helper |
| Actions | button, icon button, toast, bottom sheet/modal | confirmation dialog, undo, destructive action pattern |
| Inputs | input/search, chips, segmented control, switch, select/combobox, multi-select, date range, money range, validation | slider, stepper, saved-filter persistence |
| Feedback | skeleton, data-shape skeletons, empty state, error, offline and retry states | optimistic-state, contextual helper |
| Tender UI | local MVP ЕИС: поиск, все/любое/точная фраза, минус-слова, видимые причины совпадения, текущая выдача/история, карточка detail, именованные сохранённые запросы со всеми условиями, повторный ручной запуск и показатели последнего запуска, 44/223‑ФЗ, НМЦК, даты, этапы закупки, дополнительная информация ЕИС, personal states и bulk actions | регион/ОКПД2 через подтверждённый справочник ЕИС, обогащение карточки, compare |
| Commerce | plan card, preview paywall, access gate, Basic/PRO comparison, demo checkout states and access-state preview | Stars invoice state, entitlement gate API, subscription management |
| Admin | role-aware shell variant, server-guarded read-only Overview, aggregate access metrics, metric grid, health/data rows | chart wrapper, filter bar, user drawer, timeline, campaign composer, delivery funnel, audit event |

«Готово» означает работающий переиспользуемый React-компонент; строка
«backlog» не считается сделанной до API, states и тестов. Сначала создаются
примитивы и их variants, затем экраны на них — так новые функции не
размножают уникальные плашки.

## Экранная карта

| Доступ | Основные экраны |
|---|---|
| Preview | Welcome, value tour, demo dashboard, feature highlights, paywall entry |
| Trial / Basic | My Tenders, filters/queries, tender detail, monitoring, profile, billing |
| Expired | сохранённые данные, объяснение заморозки, paywall и help |
| Super-admin | клиентские экраны + Overview, Live Ops, Users, Commerce, Campaigns, Sources, Audit |

Operations — server-guarded read-only экран: он открывается только из
доверенной session с ролью `super_admin` и показывает лишь агрегаты
верифицированных Telegram-пользователей. Роли не меняются с trial или
оплатой: состояния `preview`, `trialing`, `paid`, `granted` и `expired`
вычисляются сервером по entitlement и источнику подписки. Вход в production
остаётся доступным только после проверки Telegram `initData`.

Экран `/queries` уже является защищённым Inertia-маршрутом: без server session
он ведёт обратно в onboarding, а с session сохраняет запрос через Laravel.
Browser preview не получает скрытого доступа к данным. Пока production не
прошёл VPS/Telegram smoke-test, этот экран проверяется локально и не рекламирует
несуществующие live результаты.

## Ближайший UI-инкремент

1. Добавить регион и ОКПД2 в local MVP только вместе с подтверждённым
   справочником служебных идентификаторов ЕИС.
2. Добавлять deadline, регион и документы в detail только после подтверждения
   разрешённого источникового формата.
3. Возвращаться к invoice или Operations UI только вместе с серверным API,
   VPS и проверенной Telegram-сессией.
