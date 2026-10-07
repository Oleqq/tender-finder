import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Badge, GlassCard, InlineAlert } from '../Components/ui';
import type { PageProps } from '../types';

type TenderDetailProps = {
    tender: {
        id: number;
        title: string;
        description: string | null;
        reg_number: string | null;
        customer: string | null;
        region: string | null;
        budget_amount: string | null;
        currency: string;
        published_at: string | null;
        deadline_at: string | null;
        is_expired: boolean;
        canonical_url: string;
        platform_url: string | null;
        source_label: string;
        query_name: string;
        match_reasons: string[];
    };
};

export default function TenderDetail() {
    const { tender } = usePage<PageProps<TenderDetailProps>>().props;

    return (
        <>
            <Head title={tender.title} />
            <AppShell
                activeNav="/tenders"
                backHref="/tenders"
                className="tender-detail-page"
                eyebrow={tender.source_label}
                title="Карточка тендера"
            >
                <GlassCard className="tender-detail-main page-enter" tone="accent">
                    <Badge tone="accent">Совпало с мониторингом</Badge>
                    {tender.is_expired ? (
                        <Badge tone="warning">Приём завершён</Badge>
                    ) : null}
                    <h2>{tender.title}</h2>
                    <p>
                        {tender.query_name} · {tender.match_reasons.join(', ')}
                    </p>
                    <Link
                        className="button button--primary button--md"
                        href={`/tenders/${tender.id}/work`}
                    >
                        Участие и задачи
                    </Link>
                </GlassCard>

                <section className="tender-detail-section page-enter page-enter--delay">
                    <h2>Главное для решения</h2>
                    <GlassCard className="tender-detail-facts" tone="quiet">
                        <dl>
                            <div>
                                <dt>Бюджет</dt>
                                <dd>
                                    {formatBudget(
                                        tender.budget_amount,
                                        tender.currency,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt>Приём заявок</dt>
                                <dd>{formatDate(tender.deadline_at)}</dd>
                            </div>
                            <div>
                                <dt>Заказчик</dt>
                                <dd>{tender.customer ?? 'Не указан'}</dd>
                            </div>
                            <div>
                                <dt>Регион</dt>
                                <dd>{tender.region ?? 'Не указан'}</dd>
                            </div>
                            <div>
                                <dt>Номер</dt>
                                <dd>{tender.reg_number ?? 'Не указан'}</dd>
                            </div>
                            <div>
                                <dt>Опубликован</dt>
                                <dd>{formatDate(tender.published_at)}</dd>
                            </div>
                        </dl>
                    </GlassCard>
                    {!tender.deadline_at ? (
                        <InlineAlert title="Проверьте срок" tone="warning">
                            Источник не указал срок подачи. Уточните его на площадке.
                        </InlineAlert>
                    ) : tender.is_expired ? (
                        <InlineAlert title="Приём заявок завершён" tone="warning">
                            Указанный срок подачи прошёл. Проверьте актуальный статус на площадке.
                        </InlineAlert>
                    ) : null}
                </section>

                <section className="tender-detail-section page-enter page-enter--later">
                    <h2>Описание</h2>
                    <GlassCard className="tender-detail-description" tone="quiet">
                        {tender.description ||
                            'Источник не передал описание этой закупки.'}
                    </GlassCard>
                </section>

                <section className="tender-detail-section page-enter page-enter--later">
                    <h2>Первоисточник</h2>
                    <GlassCard className="tender-detail-source" tone="quiet">
                        <p>Сверьте окончательные условия и документы на площадке.</p>
                        <a href={tender.canonical_url} rel="noreferrer" target="_blank">
                            {tender.platform_url
                                ? 'Открыть карточку в B2B-Center ↗'
                                : `Открыть карточку на ${tender.source_label} ↗`}
                        </a>
                        {tender.platform_url ? (
                            <a
                                href={tender.platform_url}
                                rel="noreferrer"
                                target="_blank"
                            >
                                Открыть исходное извещение ↗
                            </a>
                        ) : null}
                    </GlassCard>
                </section>
            </AppShell>
        </>
    );
}

function formatBudget(value: string | null, currency: string): string {
    if (value === null) return 'Не указан';

    return (
        new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(
            Number(value),
        ) + ` ${currency === 'RUB' ? '₽' : currency}`
    );
}

function formatDate(value: string | null): string {
    if (value === null) return 'Не указана';

    return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}
