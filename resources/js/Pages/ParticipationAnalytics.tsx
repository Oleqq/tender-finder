import { scopedUrl } from '../lib/workspace';
import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import { Badge, GlassCard } from '../Components/ui';
import { WorkspacePicker, type TeamScope } from '../Components/WorkspacePicker';
import type { PageProps } from '../types';

type Analytics = {
    period: '30' | '90' | '365' | 'all';
    summary: {
        total: number;
        active: number;
        won: number;
        lost: number;
        win_rate: number | null;
        pipeline_amount: number;
        won_amount: number;
        average_cycle_days: number | null;
        planned_margin: number;
        actual_margin: number;
    };
    stages: Record<string, { label: string; count: number }>;
    loss_reasons: Array<{ reason: string; count: number }>;
    members: Array<{
        id: number;
        name: string;
        total: number;
        active: number;
        won: number;
        win_rate: number | null;
    }>;
    series: Array<{ month: string; total: number; won: number; lost: number }>;
};

const money = (value: number): string =>
    new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: 0,
    }).format(value);

export default function ParticipationAnalytics() {
    const { analytics, team } =
        usePage<PageProps<TeamScope & { analytics: Analytics }>>().props;
    const maxStage = Math.max(
        1,
        ...Object.values(analytics.stages).map((s) => s.count),
    );
    const exportUrl = scopedUrl(
        `/participation/analytics/export?period=${analytics.period}`,
        team,
    );

    return (
        <>
            <Head title="Аналитика участия" />
            <AppShell
                title="Аналитика"
                eyebrow="Результаты участия"
                className="work-page"
                activeNav="/tenders"
                wide
            >
                <TenderWorkNav active="/participation/analytics" />
                <WorkspacePicker path="/participation/analytics" />
                <div className="work-toolbar analytics-toolbar">
                    <nav className="work-stages" aria-label="Период аналитики">
                        {[
                            ['30', '30 дней'],
                            ['90', '90 дней'],
                            ['365', 'Год'],
                            ['all', 'Всё время'],
                        ].map(([period, label]) => (
                            <Link
                                key={period}
                                className={
                                    analytics.period === period ? 'is-active' : ''
                                }
                                href={scopedUrl(
                                    `/participation/analytics?period=${period}`,
                                    team,
                                )}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                    <a className="button button--secondary" href={exportUrl}>
                        Скачать CSV
                    </a>
                </div>

                <section className="analytics-summary" aria-label="Основные показатели">
                    <GlassCard>
                        <span>В работе</span>
                        <strong>{analytics.summary.active}</strong>
                        <small>из {analytics.summary.total} заявок</small>
                    </GlassCard>
                    <GlassCard>
                        <span>Победы</span>
                        <strong>
                            {analytics.summary.win_rate === null
                                ? '—'
                                : `${analytics.summary.win_rate}%`}
                        </strong>
                        <small>{analytics.summary.won} выиграно</small>
                    </GlassCard>
                    <GlassCard>
                        <span>НМЦК в работе</span>
                        <strong>{money(analytics.summary.pipeline_amount)}</strong>
                        <small>выиграно: {money(analytics.summary.won_amount)}</small>
                    </GlassCard>
                    <GlassCard>
                        <span>Средний цикл</span>
                        <strong>
                            {analytics.summary.average_cycle_days === null
                                ? '—'
                                : `${analytics.summary.average_cycle_days} дн.`}
                        </strong>
                        <small>до победы или проигрыша</small>
                    </GlassCard>
                    <GlassCard>
                        <span>Плановая маржа</span>
                        <strong>{money(analytics.summary.planned_margin)}</strong>
                        <small>факт: {money(analytics.summary.actual_margin)}</small>
                    </GlassCard>
                </section>

                <div className="analytics-grid">
                    <GlassCard className="work-card analytics-panel">
                        <h2>Воронка по этапам</h2>
                        <div className="analytics-bars">
                            {Object.entries(analytics.stages).map(([key, stage]) => (
                                <div className="analytics-bar" key={key}>
                                    <span>{stage.label}</span>
                                    <div>
                                        <i
                                            style={{
                                                width: `${(stage.count / maxStage) * 100}%`,
                                            }}
                                        />
                                    </div>
                                    <strong>{stage.count}</strong>
                                </div>
                            ))}
                        </div>
                    </GlassCard>
                    <GlassCard className="work-card analytics-panel">
                        <h2>Причины проигрышей</h2>
                        {analytics.loss_reasons.length === 0 ? (
                            <p>Причины пока не накоплены.</p>
                        ) : (
                            <ol className="analytics-reasons">
                                {analytics.loss_reasons.map((reason) => (
                                    <li key={reason.reason}>
                                        <span>{reason.reason}</span>
                                        <Badge tone="danger">{reason.count}</Badge>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </GlassCard>
                </div>

                {team ? (
                    <GlassCard className="work-card analytics-panel">
                        <h2>Результаты сотрудников</h2>
                        <div className="analytics-table-wrap">
                            <table className="analytics-table">
                                <thead>
                                    <tr>
                                        <th>Сотрудник</th>
                                        <th>Всего</th>
                                        <th>В работе</th>
                                        <th>Победы</th>
                                        <th>Конверсия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {analytics.members.map((member) => (
                                        <tr key={member.id}>
                                            <th>{member.name}</th>
                                            <td>{member.total}</td>
                                            <td>{member.active}</td>
                                            <td>{member.won}</td>
                                            <td>
                                                {member.win_rate === null
                                                    ? '—'
                                                    : `${member.win_rate}%`}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </GlassCard>
                ) : null}

                <GlassCard className="work-card analytics-panel">
                    <h2>Заявки по месяцам</h2>
                    {analytics.series.length === 0 ? (
                        <p>За выбранный период заявок нет.</p>
                    ) : (
                        <div className="analytics-series">
                            {analytics.series.map((point) => (
                                <div key={point.month}>
                                    <strong>{point.month}</strong>
                                    <span>Всего {point.total}</span>
                                    <span className="is-won">Победы {point.won}</span>
                                    <span className="is-lost">
                                        Проигрыши {point.lost}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </GlassCard>
            </AppShell>
        </>
    );
}
