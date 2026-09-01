import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { MetricCard } from '../Components/MetricCard';
import {
    Badge,
    Button,
    DataRow,
    GlassCard,
    InlineAlert,
    SegmentedControl,
} from '../Components/ui';
import type { PageProps } from '../types';

type PeriodKey = '7d' | '30d' | '90d';

type GrowthDashboard = {
    period: {
        key: PeriodKey;
        days: number;
        startsAt: string;
        generatedAt: string;
    };
    audience: {
        total: number;
        newUsers: number;
        miniAppActive: number;
    };
    activation: {
        trialsStarted: number;
        trialsTotal: number;
        starsStarted: number;
    };
    access: {
        preview: number;
        trialing: number;
        paid: number;
        granted: number;
        expired: number;
    };
    funnel: {
        registered: number;
        trialed: number;
        paid: number;
        trialRate: number;
        paidRate: number;
    };
    series: Array<{
        date: string;
        label: string;
        registrations: number;
        trials: number;
        starsStarts: number;
    }>;
    commerce: {
        state: 'pending';
        message: string;
    };
};

type OperationsProps = { dashboard: GrowthDashboard };

const periodOptions = [
    { value: '7d', label: '7 дней' },
    { value: '30d', label: '30 дней' },
    { value: '90d', label: '90 дней' },
];

export default function OperationsDashboard() {
    const { dashboard } = usePage<PageProps<OperationsProps>>().props;
    const [refreshing, setRefreshing] = useState(false);

    const activeRate = percentage(
        dashboard.audience.miniAppActive,
        dashboard.audience.total,
    );
    const paidFromAudience = percentage(
        dashboard.funnel.paid,
        dashboard.funnel.registered,
    );
    const generatedAt = formatTimestamp(dashboard.period.generatedAt);

    const changePeriod = (period: string): void => {
        if (period === dashboard.period.key || refreshing) {
            return;
        }

        setRefreshing(true);
        router.get(
            '/operations',
            { period },
            {
                only: ['dashboard'],
                preserveScroll: true,
                onFinish: () => setRefreshing(false),
            },
        );
    };

    const refresh = (): void => {
        if (refreshing) {
            return;
        }

        setRefreshing(true);
        router.reload({
            only: ['dashboard'],
            onFinish: () => setRefreshing(false),
        });
    };

    return (
        <>
            <Head title="Аналитика" />
            <AppShell
                activeNav="/operations"
                action={
                    <button
                        aria-label="Обновить показатели"
                        aria-live="polite"
                        className="icon-button"
                        disabled={refreshing}
                        onClick={refresh}
                        type="button"
                    >
                        <Icon name="refresh" size={20} />
                    </button>
                }
                eyebrow="Владелец · сводные данные"
                role="super_admin"
                title="Аналитика"
                wide
            >
                <section className="analytics-hero page-enter">
                    <div>
                        <Badge tone="accent">
                            <span className="status-dot" /> Данные по продукту
                        </Badge>
                        <h2>
                            Рост без
                            <br />
                            <em>догадок.</em>
                        </h2>
                        <p>
                            Аудитория, trial и доступы — только по данным, которые
                            продукт уже хранит.
                        </p>
                    </div>
                    <div className="analytics-hero__meta">
                        <span>Период</span>
                        <strong>{periodLabel(dashboard.period.days)}</strong>
                        <small>
                            {refreshing ? 'Обновляем…' : `Обновлено ${generatedAt}`}
                        </small>
                    </div>
                </section>

                <div className="analytics-toolbar page-enter page-enter--delay">
                    <SegmentedControl
                        label="Период аналитики"
                        onChange={changePeriod}
                        options={periodOptions}
                        value={dashboard.period.key}
                    />
                    <Button
                        disabled={refreshing}
                        icon="refresh"
                        onClick={refresh}
                        size="sm"
                        variant="secondary"
                    >
                        {refreshing ? 'Обновляем' : 'Обновить'}
                    </Button>
                </div>

                <section
                    aria-busy={refreshing}
                    aria-label="Ключевые показатели"
                    className="analytics-metric-grid page-enter page-enter--delay"
                >
                    <MetricCard
                        accent
                        detail={`новых за ${periodLabel(dashboard.period.days)}`}
                        icon="user"
                        label="Аудитория"
                        trend={{
                            direction: 'neutral',
                            label: 'Новые пользователи за период',
                            value: `+${formatNumber(dashboard.audience.newUsers)}`,
                        }}
                        value={formatNumber(dashboard.audience.total)}
                    />
                    <MetricCard
                        detail="открывали Mini App"
                        icon="wave"
                        label="Недавняя активность"
                        trend={{
                            direction: 'neutral',
                            label: 'Доля аудитории, открывавшей Mini App',
                            value: `${activeRate}% аудитории`,
                        }}
                        value={formatNumber(dashboard.audience.miniAppActive)}
                    />
                    <MetricCard
                        detail={`всего trial: ${formatNumber(dashboard.activation.trialsTotal)}`}
                        icon="spark"
                        label="Начали trial"
                        trend={{
                            direction: 'neutral',
                            label: 'Старты за выбранный период',
                            value: `${formatNumber(dashboard.activation.trialsStarted)} за ${periodLabel(dashboard.period.days)}`,
                        }}
                        value={formatNumber(dashboard.activation.trialsStarted)}
                    />
                    <MetricCard
                        detail="активный доступ сейчас"
                        icon="chart"
                        label="Платный доступ"
                        trend={{
                            direction: 'neutral',
                            label: 'Доля платного доступа от аудитории',
                            value: `${paidFromAudience}% аудитории`,
                        }}
                        value={formatNumber(dashboard.access.paid)}
                    />
                </section>

                <div className="analytics-main-grid page-enter page-enter--later">
                    <Funnel dashboard={dashboard} paidFromAudience={paidFromAudience} />
                    <AccessDistribution access={dashboard.access} />
                </div>

                <GrowthChart dashboard={dashboard} />

                <InlineAlert title="Денежная картина появится позже" tone="neutral">
                    {dashboard.commerce.message} Сейчас видно только{' '}
                    {formatNumber(dashboard.activation.starsStarted)} запусков Stars за
                    выбранный период — без сумм, прогнозов и тестовых денег.
                </InlineAlert>

                <section className="analytics-note page-enter page-enter--later">
                    <Icon name="shield" size={18} />
                    <p>
                        В экране нет личных профилей, Telegram ID, поисковых фраз и
                        технических показателей. «Недавняя активность» означает вход в
                        Mini App, а не использование Telegram-бота.
                    </p>
                </section>
            </AppShell>
        </>
    );
}

function Funnel({
    dashboard,
    paidFromAudience,
}: {
    dashboard: GrowthDashboard;
    paidFromAudience: number;
}) {
    const steps = [
        {
            label: 'Подтверждённая аудитория',
            value: dashboard.funnel.registered,
            share: 100,
            detail: 'за всё время',
        },
        {
            label: 'Начинали trial',
            value: dashboard.funnel.trialed,
            share: dashboard.funnel.trialRate,
            detail: `${dashboard.funnel.trialRate}% от аудитории`,
        },
        {
            label: 'Оплачивают сейчас',
            value: dashboard.funnel.paid,
            share: paidFromAudience,
            detail: `${dashboard.funnel.paidRate}% среди начинавших trial`,
        },
    ];

    return (
        <GlassCard as="section" className="analytics-panel analytics-funnel">
            <div className="analytics-panel__heading">
                <div>
                    <p>Путь к ценности</p>
                    <h2>Воронка доступа</h2>
                </div>
                <Badge tone="accent">сейчас</Badge>
            </div>
            <ol aria-label="Воронка доступа" className="analytics-funnel__steps">
                {steps.map((step) => (
                    <li key={step.label}>
                        <div>
                            <span>{step.label}</span>
                            <small>{step.detail}</small>
                        </div>
                        <strong>{formatNumber(step.value)}</strong>
                        <span
                            aria-label={`${step.label}: ${step.share}%`}
                            className="analytics-funnel__bar"
                        >
                            <span
                                style={{
                                    width: `${Math.max(0, Math.min(100, step.share))}%`,
                                }}
                            />
                        </span>
                    </li>
                ))}
            </ol>
        </GlassCard>
    );
}

function AccessDistribution({ access }: { access: GrowthDashboard['access'] }) {
    return (
        <GlassCard
            as="section"
            className="analytics-panel analytics-access"
            tone="quiet"
        >
            <div className="analytics-panel__heading">
                <div>
                    <p>Текущая база</p>
                    <h2>Состояние доступа</h2>
                </div>
            </div>
            <div className="analytics-access__rows">
                <DataRow
                    detail="ещё знакомятся с продуктом"
                    icon="user"
                    label="В начале пути"
                    value={formatNumber(access.preview)}
                />
                <DataRow
                    detail="пробный доступ активен"
                    icon="spark"
                    label="На trial"
                    value={formatNumber(access.trialing)}
                />
                <DataRow
                    detail="доступ через Stars активен"
                    icon="chart"
                    label="Оплачивают"
                    value={formatNumber(access.paid)}
                />
                <DataRow
                    detail="выдано вручную, не считается оплатой"
                    icon="layers"
                    label="Ручной доступ"
                    value={formatNumber(access.granted)}
                />
                <DataRow
                    detail="trial или доступ закончился"
                    icon="calendar"
                    label="Доступ завершён"
                    value={formatNumber(access.expired)}
                />
            </div>
        </GlassCard>
    );
}

function GrowthChart({ dashboard }: { dashboard: GrowthDashboard }) {
    const peak = useMemo(
        () =>
            Math.max(
                1,
                ...dashboard.series.flatMap((day) => [
                    day.registrations,
                    day.trials,
                    day.starsStarts,
                ]),
            ),
        [dashboard.series],
    );
    const labelEvery = Math.max(1, Math.ceil(dashboard.series.length / 5));

    return (
        <GlassCard
            as="section"
            className="analytics-chart page-enter page-enter--later"
        >
            <div className="analytics-panel__heading">
                <div>
                    <p>Накопление спроса</p>
                    <h2>Динамика за {dashboard.period.days} дней</h2>
                </div>
                <div aria-label="Легенда графика" className="analytics-chart__legend">
                    <span>
                        <i className="is-registration" />
                        Регистрации
                    </span>
                    <span>
                        <i className="is-trial" />
                        Trial
                    </span>
                    <span>
                        <i className="is-stars" />
                        Stars
                    </span>
                </div>
            </div>
            <div
                aria-label="Дневная динамика регистраций, trial и запусков Stars"
                className="analytics-chart__plot"
                role="img"
            >
                {dashboard.series.map((day, index) => (
                    <div className="analytics-chart__day" key={day.date}>
                        <div className="analytics-chart__bars">
                            <span
                                aria-label={`Регистрации ${day.label}: ${day.registrations}`}
                                className="is-registration"
                                style={{ height: barHeight(day.registrations, peak) }}
                            />
                            <span
                                aria-label={`Trial ${day.label}: ${day.trials}`}
                                className="is-trial"
                                style={{ height: barHeight(day.trials, peak) }}
                            />
                            <span
                                aria-label={`Stars ${day.label}: ${day.starsStarts}`}
                                className="is-stars"
                                style={{ height: barHeight(day.starsStarts, peak) }}
                            />
                        </div>
                        {index % labelEvery === 0 ||
                        index === dashboard.series.length - 1 ? (
                            <small>{day.label}</small>
                        ) : null}
                    </div>
                ))}
            </div>
            <ul className="sr-only">
                {dashboard.series.map((day) => (
                    <li key={day.date}>
                        {day.label}: регистраций {day.registrations}, trial {day.trials}
                        , Stars {day.starsStarts}.
                    </li>
                ))}
            </ul>
        </GlassCard>
    );
}

function percentage(value: number, total: number): number {
    if (total === 0) {
        return 0;
    }

    return Math.round((value / total) * 1000) / 10;
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat('ru-RU').format(value);
}

function periodLabel(days: number): string {
    return days === 7 ? '7 дней' : `${days} дней`;
}

function formatTimestamp(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        month: 'short',
    }).format(new Date(value));
}

function barHeight(value: number, peak: number): string {
    return `${value === 0 ? 4 : Math.max(10, (value / peak) * 100)}%`;
}
