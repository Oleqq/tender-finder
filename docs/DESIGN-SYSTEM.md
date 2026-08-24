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
| Tender UI | tender card, demo metrics | tender detail, save/hide, match explanation, compare, saved filter |
| Commerce | plan card, preview paywall, access gate, Basic/PRO comparison, demo checkout states and access-state preview | Stars invoice state, entitlement gate API, subscription management |
| Admin | role-aware shell variant, read-only demo Overview/Live Ops, metric grid, health/data rows | server policy, real read models, chart wrapper, filter bar, user drawer, timeline, campaign composer, delivery funnel, audit event |

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

Текущий Operations demo — только визуальный read-only образец внутри того же
`AppShell`. Он не является административным endpoint, не назначает роль и не
даёт доступа к данным. Настоящая запись `super_admin` и navigation появятся
только из доверенного server-side session/policy после проверки Telegram
`initData`.

## Ближайший UI-инкремент

1. Подключить primitives к настоящему query builder после появления domain/API.
2. Добавить invoice/loading/error patterns только вместе с серверным Stars API.
3. Заменить Operations demo server-side policy и read models после managed
   PostgreSQL/Redis и validated Telegram session.
