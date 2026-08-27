import { Head, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Badge, GlassCard } from '../Components/ui';
import type { PageProps } from '../types';

type AttachmentDto = {
    label: string;
    url: string;
    mime_type: string | null;
    size_bytes: number | null;
};

type TenderDetailDto = {
    id: number;
    title: string;
    description: string | null;
    customer: string | null;
    category: string | null;
    procurement_law: string | null;
    reg_number: string | null;
    region: string | null;
    budget_amount: string | null;
    currency: string;
    published_at: string | null;
    deadline_at: string | null;
    canonical_url: string;
    source_label: string;
    attachments: AttachmentDto[];
};

type MvpTenderDetailProps = { tender: TenderDetailDto };

export default function MvpTenderDetail() {
    const { tender } = usePage<PageProps<MvpTenderDetailProps>>().props;

    return (
        <>
            <Head title={`${tender.title} — Tender Finder`} />
            <AppShell
                backHref="/local/mvp-operator"
                className="mvp-tender-detail"
                eyebrow="Локальный MVP · карточка тендера"
                navigationVisible={false}
                role="super_admin"
                title="Тендер"
            >
                <GlassCard className="mvp-tender-detail__hero" tone="accent">
                    <Badge tone="accent">ЕИС · госзакупки</Badge>
                    <h2>{tender.title}</h2>
                    <p>
                        Внутри приложения показаны только сведения, которые передал
                        источник. Ничего не дополнено догадками.
                    </p>
                </GlassCard>

                <section className="mvp-tender-detail__section">
                    <h2>Основная информация</h2>
                    <dl className="mvp-tender-detail__grid">
                        <DetailRow label="Заказчик" value={tender.customer} />
                        <DetailRow
                            label="Цена"
                            value={formatBudget(tender.budget_amount, tender.currency)}
                        />
                        <DetailRow label="Регион" value={tender.region} />
                        <DetailRow
                            label="Опубликован"
                            value={formatDate(tender.published_at)}
                        />
                        <DetailRow
                            label="Срок подачи"
                            value={formatDate(tender.deadline_at)}
                        />
                        <DetailRow label="Номер закупки" value={tender.reg_number} />
                        <DetailRow
                            label="Категория источника"
                            value={tender.category}
                        />
                        <DetailRow
                            label="Закон"
                            value={
                                tender.procurement_law
                                    ? `${tender.procurement_law}-ФЗ`
                                    : null
                            }
                        />
                    </dl>
                </section>

                <section className="mvp-tender-detail__section">
                    <h2>Описание</h2>
                    {tender.description ? (
                        <p className="mvp-tender-detail__description">
                            {tender.description}
                        </p>
                    ) : (
                        <p className="mvp-tender-detail__missing">
                            Источник не передал описание для этой карточки.
                        </p>
                    )}
                </section>

                <section className="mvp-tender-detail__section">
                    <h2>ТЗ и вложения</h2>
                    {tender.attachments.length > 0 ? (
                        <ul className="mvp-tender-detail__attachments">
                            {tender.attachments.map((attachment) => (
                                <li key={attachment.url}>
                                    <a
                                        href={attachment.url}
                                        rel="noreferrer"
                                        target="_blank"
                                    >
                                        {attachment.label}
                                    </a>
                                    <span>{attachmentMeta(attachment)}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mvp-tender-detail__missing">
                            RSS ЕИС не передала ссылки на ТЗ или вложения. Мы не создаём
                            фальшивые PDF и не извлекаем защищённые документы.
                        </p>
                    )}
                </section>

                <GlassCard className="mvp-tender-detail__source" tone="quiet">
                    <p>Источник</p>
                    <strong>{tender.source_label}</strong>
                    <a href={tender.canonical_url} rel="noreferrer" target="_blank">
                        Открыть исходную карточку
                    </a>
                </GlassCard>
            </AppShell>
        </>
    );
}

function DetailRow({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt>{label}</dt>
            <dd>{value ?? 'Не передано источником'}</dd>
        </div>
    );
}

function attachmentMeta(attachment: AttachmentDto): string {
    const parts = [attachment.mime_type];

    if (attachment.size_bytes !== null) {
        parts.push(formatSize(attachment.size_bytes));
    }

    return parts.filter(Boolean).join(' · ') || 'Ссылка источника';
}

function formatBudget(amount: string | null, currency: string): string | null {
    if (!amount) {
        return null;
    }

    return `${new Intl.NumberFormat('ru-RU', {
        maximumFractionDigits: 0,
    }).format(Number(amount))} ${currency === 'RUB' ? '₽' : currency}`;
}

function formatDate(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(value));
}

function formatSize(sizeBytes: number): string {
    if (sizeBytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(sizeBytes / 1024))} КБ`;
    }

    return `${(sizeBytes / (1024 * 1024)).toFixed(1)} МБ`;
}
