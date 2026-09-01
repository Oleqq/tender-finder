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
| Tender UI | local MVP ЕИС: поиск, все/любое/точная фраза, минус-слова, видимые причины совпадения, текущая выдача/история, карточка detail, именованные сохранённые запросы со всеми условиями, история запусков и «только новые», 44/223‑ФЗ, НМЦК, даты, регион/ОКПД2 через справочники ЕИС, этапы закупки, дополнительная информация, сравнение 2–5 карточек, personal states и bulk actions | обогащение карточки |
| Commerce | plan card, preview paywall, access gate, Basic/PRO comparison, demo checkout states and access-state preview | Stars invoice state, entitlement gate API, subscription management |
| Admin | role-aware shell variant, server-guarded read-only агрегаты, period switcher, funnel, growth chart и access distribution | user drawer, campaigns, audit и технический Live Ops остаются вне MVP |

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
| Super-admin | клиентские экраны + закрытая «Аналитика» аудитории и воронки; Users, Campaigns, Sources, Audit и технический Live Ops не входят в MVP |

«Аналитика» — server-guarded read-only экран: он открывается только из
доверенной session с ролью `super_admin` и показывает лишь агрегаты
верифицированных Telegram-пользователей. На широком экране он использует
data-first сетку, на мобильном — плотную последовательность тех же блоков;
CSS-графики всегда сопровождаются текстовыми значениями. Роли не меняются с
trial или оплатой: состояния `preview`, `trialing`, `paid`, `granted` и
`expired` вычисляются сервером по entitlement и источнику подписки. Вход в
production остаётся доступным только после проверки Telegram `initData`.

Экран `/queries` уже является защищённым Inertia-маршрутом: без server session
он ведёт обратно в onboarding, а с session сохраняет запрос через Laravel.
Browser preview не получает скрытого доступа к данным. Пока production не
прошёл VPS/Telegram smoke-test, этот экран проверяется локально и не рекламирует
несуществующие live результаты.

## Ближайший UI-инкремент

1. Провести ручную приёмку справочников, сравнения и истории запусков на
   нескольких живых выдачах ЕИС.
2. Добавлять deadline, регион и документы в detail только после подтверждения
   разрешённого источникового формата.
3. Реализовать закрытую маркетинговую «Аналитику» вместе с server-side
   read-model, периодами и проверками; не добавлять Users, рассылки или
   технический мониторинг без отдельных решений.
