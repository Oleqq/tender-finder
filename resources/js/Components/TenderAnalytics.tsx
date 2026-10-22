import '../../css/tender-analytics.css';

import { useEffect, useState } from 'react';
import {
    deadlineSummary,
    summaryDate,
    summaryMoney,
    type TenderSummary,
} from '../lib/tenderSummary';
import { Badge, GlassCard } from './ui';
import type { TenderInsights as Insights } from '../lib/tenderAnalytics';
import { TenderInsights } from './TenderInsights';

export function TenderAnalytics({
    summary,
    compact = false,
    analytics,
}: {
    summary: TenderSummary;
    compact?: boolean;
    analytics?: Insights;
}) {
    const [now, setNow] = useState(Date.now);
    useEffect(() => {
        const refresh = () => setNow(Date.now());
        const timer = window.setInterval(refresh, 30_000);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, []);
    const deadline = deadlineSummary(summary, now);

    if (compact) {
        return (
            <div className="tender-analytics-compact">
                <Badge tone={deadline.tone}>
                    {deadline.remaining !== null &&
                    deadline.remaining > 0 &&
                    !deadline.contradictory
                        ? 'Осталось '
                        : ''}
                    {deadline.label}
                </Badge>
                {summary.published_at ? (
                    <span>Публикация: {summaryDate(summary.published_at)}</span>
                ) : null}
            </div>
        );
    }

    return (
        <GlassCard
            className={
                'tender-analytics tender-analytics--' + (analytics?.tier ?? 'basic')
            }
        >
            <header className="tender-analytics__heading">
                <div>
                    <p>Быстрая оценка</p>
                    <h2>
                        {analytics?.tier === 'ultra'
                            ? 'Аналитика Ultra'
                            : analytics?.tier === 'pro'
                              ? 'Аналитика Pro'
                              : 'Краткая аналитика'}
                    </h2>
                </div>
                <Badge tone={deadline.tone}>
                    {deadline.contradictory
                        ? 'Уточните даты'
                        : deadline.elapsed
                          ? 'Срок истёк'
                          : deadline.remaining === null
                            ? 'Нет срока'
                            : deadline.remaining <= 86_400_000
                              ? 'До суток'
                              : 'Есть время'}
                </Badge>
            </header>
            <dl className="tender-analytics__metrics">
                <div>
                    <dt>Бюджет закупки</dt>
                    <dd>{summaryMoney(summary.budget_amount, summary.currency)}</dd>
                </div>
                <div>
                    <dt>До окончания подачи</dt>
                    <dd>{deadline.label}</dd>
                </div>
            </dl>
            {deadline.progress !== null && !deadline.contradictory ? (
                <div
                    aria-label={
                        'Прошло ' + Math.floor(deadline.progress) + '% периода подачи'
                    }
                    className="tender-analytics__timeline"
                    role="img"
                >
                    <span style={{ width: deadline.progress + '%' }} />
                </div>
            ) : null}
            <dl className="tender-analytics__dates">
                <div>
                    <dt>Публикация</dt>
                    <dd>{summaryDate(summary.published_at)}</dd>
                </div>
                <div>
                    <dt>Подать заявку до</dt>
                    <dd>{summaryDate(summary.deadline_at)}</dd>
                </div>
            </dl>
            {summary.application_security !== null ||
            summary.contract_security !== null ? (
                <dl className="tender-analytics__terms">
                    <div>
                        <dt>Обеспечение заявки</dt>
                        <dd>{summary.application_security ?? 'Не указано'}</dd>
                    </div>
                    <div>
                        <dt>Обеспечение контракта</dt>
                        <dd>{summary.contract_security ?? 'Не указано'}</dd>
                    </div>
                </dl>
            ) : null}
            {summary.delivery_place ? (
                <p className="tender-analytics__place">
                    Место исполнения: <strong>{summary.delivery_place}</strong>
                </p>
            ) : null}
            {deadline.contradictory ? (
                <p className="tender-analytics__notice">
                    Источник указал срок подачи раньше даты публикации. Уточните даты на
                    площадке.
                </p>
            ) : deadline.remaining === null || summary.budget_amount === null ? (
                <p className="tender-analytics__notice">
                    {[
                        summary.budget_amount === null ? 'Бюджет не указан' : '',
                        deadline.remaining === null ? 'срок подачи не указан' : '',
                    ]
                        .filter(Boolean)
                        .join('; ')}
                    . Уточните условия на площадке.
                </p>
            ) : null}
            <p className="tender-analytics__source">
                {summary.source_label} · сроки по Москве
                {summary.observed_at
                    ? ' · данные получены ' + summaryDate(summary.observed_at)
                    : ''}
            </p>
            {analytics ? (
                <TenderInsights analytics={analytics} summary={summary} />
            ) : null}
        </GlassCard>
    );
}
