import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, FieldError, GlassCard, InlineAlert } from '../Components/ui';
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
    delivery_place: string | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    postal_address: string | null;
    application_security: string | null;
    contract_security: string | null;
    enriched_at: string | null;
    can_enrich: boolean;
    note: string | null;
    tags: string[];
    next_action_on: string | null;
};

type MvpTenderDetailProps = { tender: TenderDetailDto };

export default function MvpTenderDetail() {
    const { tender: initialTender } = usePage<PageProps<MvpTenderDetailProps>>().props;
    const [tender, setTender] = useState(initialTender);
    const [note, setNote] = useState(initialTender.note ?? '');
    const [tags, setTags] = useState(initialTender.tags.join(', '));
    const [nextActionOn, setNextActionOn] = useState(
        initialTender.next_action_on ?? '',
    );
    const [saving, setSaving] = useState(false);
    const [enriching, setEnriching] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');

    const saveAnnotation = async (): Promise<void> => {
        setSaving(true);
        setError('');
        setNotice('');

        try {
            const response = await window.axios.patch<{ tender: TenderDetailDto }>(
                `/local/mvp/tenders/${tender.id}/annotation`,
                {
                    note: note.trim() || null,
                    tags: parseTags(tags),
                    next_action_on: nextActionOn || null,
                },
            );
            setTender(response.data.tender);
            setTags(response.data.tender.tags.join(', '));
            setNotice('Личная заметка сохранена.');
        } catch (requestError) {
            setError(
                requestErrorMessage(requestError, 'Не удалось сохранить заметку.'),
            );
        } finally {
            setSaving(false);
        }
    };

    const enrich = async (): Promise<void> => {
        setEnriching(true);
        setError('');
        setNotice('');

        try {
            const response = await window.axios.post<{ tender: TenderDetailDto }>(
                `/local/mvp/tenders/${tender.id}/enrich`,
            );
            setTender(response.data.tender);
            setNotice('Публичные сведения карточки ЕИС обновлены.');
        } catch (requestError) {
            setError(
                requestErrorMessage(
                    requestError,
                    'Не удалось обновить публичные сведения ЕИС.',
                ),
            );
        } finally {
            setEnriching(false);
        }
    };

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
                    {tender.can_enrich ? (
                        <Button
                            disabled={enriching}
                            onClick={enrich}
                            variant="secondary"
                        >
                            {enriching
                                ? 'Получаем сведения ЕИС…'
                                : tender.enriched_at
                                  ? 'Обновить сведения ЕИС'
                                  : 'Дополнить из карточки ЕИС'}
                        </Button>
                    ) : null}
                    {tender.enriched_at ? (
                        <small>
                            Последнее обогащение: {formatDateTime(tender.enriched_at)}
                        </small>
                    ) : null}
                </GlassCard>

                {notice ? (
                    <InlineAlert title="Готово" tone="success">
                        {notice}
                    </InlineAlert>
                ) : null}
                {error ? <FieldError>{error}</FieldError> : null}

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
                        <DetailRow
                            label="Место поставки"
                            value={tender.delivery_place}
                        />
                        <DetailRow
                            label="Обеспечение заявки"
                            value={tender.application_security}
                        />
                        <DetailRow
                            label="Обеспечение контракта"
                            value={tender.contract_security}
                        />
                    </dl>
                </section>

                <section className="mvp-tender-detail__section">
                    <h2>Контактная информация</h2>
                    <dl className="mvp-tender-detail__grid">
                        <DetailRow
                            label="Контактное лицо"
                            value={tender.contact_name}
                        />
                        <DetailRow label="Телефон" value={tender.contact_phone} />
                        <DetailRow label="Email" value={tender.contact_email} />
                        <DetailRow
                            label="Почтовый адрес"
                            value={tender.postal_address}
                        />
                    </dl>
                </section>

                <section className="mvp-tender-detail__section mvp-tender-detail__annotation">
                    <h2>Моя работа с закупкой</h2>
                    <p>Заметка, теги и дата следующего действия видны только вам.</p>
                    <label className="form-field">
                        <span>Заметка</span>
                        <textarea
                            maxLength={5000}
                            onChange={(event) => setNote(event.target.value)}
                            placeholder="Что проверить, кому позвонить, какие документы подготовить"
                            rows={5}
                            value={note}
                        />
                    </label>
                    <label className="form-field">
                        <span>Теги через запятую</span>
                        <input
                            onChange={(event) => setTags(event.target.value)}
                            placeholder="приоритет, позвонить, документы"
                            value={tags}
                        />
                    </label>
                    <label className="form-field">
                        <span>Следующее действие</span>
                        <input
                            onChange={(event) => setNextActionOn(event.target.value)}
                            type="date"
                            value={nextActionOn}
                        />
                    </label>
                    <Button disabled={saving} onClick={saveAnnotation}>
                        {saving ? 'Сохраняем…' : 'Сохранить заметку'}
                    </Button>
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
                            Ссылки на документы ещё не получены. Для карточки ЕИС
                            нажмите «Дополнить из карточки ЕИС»; защищённые документы
                            приложение не извлекает.
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

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function parseTags(value: string): string[] {
    const unique = new Map<string, string>();

    value
        .split(/[,;\n]+/)
        .map((tag) => tag.trim())
        .filter(Boolean)
        .slice(0, 10)
        .forEach((tag) => unique.set(tag.toLocaleLowerCase('ru-RU'), tag));

    return [...unique.values()];
}

function requestErrorMessage(error: unknown, fallback: string): string {
    const validation = (
        error as { response?: { data?: { errors?: Record<string, string[]> } } }
    ).response?.data?.errors;
    const message = validation ? Object.values(validation).flat()[0] : undefined;

    return message ?? fallback;
}

function formatSize(sizeBytes: number): string {
    if (sizeBytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(sizeBytes / 1024))} КБ`;
    }

    return `${(sizeBytes / (1024 * 1024)).toFixed(1)} МБ`;
}
