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

type TenderStatus = 'new' | 'favorite' | 'potential' | 'dismissed' | 'archived';
type SearchMatchMode = 'all' | 'any' | 'exact';

type TenderMatchReason = {
    mode: SearchMatchMode;
    matched_terms: string[];
    minus_keywords_checked: string[];
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
    status: TenderStatus;
    match_reason: TenderMatchReason | null;
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
    const [isUpdating, setIsUpdating] = useState(false);
    const [saving, setSaving] = useState(false);
    const [enriching, setEnriching] = useState(false);
    const [actionError, setActionError] = useState('');
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

    const updateStatus = async (status: TenderStatus): Promise<void> => {
        setActionError('');
        setIsUpdating(true);

        try {
            const response = await window.axios.post<{
                tender: Pick<TenderDetailDto, 'id' | 'status' | 'match_reason'>;
            }>(`/local/mvp/tenders/${tender.id}/status`, { status });

            setTender((current) => ({ ...current, ...response.data.tender }));
        } catch {
            setActionError(
                'Не удалось сохранить отметку. Данные тендера и прежний статус не изменены.',
            );
        } finally {
            setIsUpdating(false);
        }
    };

    return (
        <>
            <Head title={tender.title} />
            <AppShell
                backHref="/local/mvp-operator"
                className="mvp-tender-detail"
                eyebrow="ЕИС · карточка тендера"
                navigationVisible={false}
                role="super_admin"
                title="Тендер"
                wide
            >
                <div className="mvp-tender-detail__layout">
                    <div className="mvp-tender-detail__primary">
                        <GlassCard className="mvp-tender-detail__hero" tone="accent">
                            <div className="mvp-tender-detail__eyebrow-row">
                                <Badge tone={statusTone(tender.status)}>
                                    {statusLabel(tender.status)}
                                </Badge>
                                <span>ЕИС · госзакупки</span>
                            </div>
                            <h2>{tender.title}</h2>
                            <div
                                aria-label="Тип закупки"
                                className="mvp-tender-detail__facts"
                            >
                                {tender.category ? (
                                    <span>{tender.category}</span>
                                ) : null}
                                {tender.procurement_law ? (
                                    <span>{tender.procurement_law}-ФЗ</span>
                                ) : null}
                                {tender.region ? <span>{tender.region}</span> : null}
                            </div>
                            <p>
                                Данные и ссылки — только из источника. Проверьте
                                исходную карточку перед решением об участии.
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
                                    Последнее обогащение:{' '}
                                    {formatDateTime(tender.enriched_at)}
                                </small>
                            ) : null}
                        </GlassCard>

                        {notice ? (
                            <InlineAlert title="Готово" tone="success">
                                {notice}
                            </InlineAlert>
                        ) : null}
                        {error ? <FieldError>{error}</FieldError> : null}

                        <section
                            aria-labelledby="tender-decision-title"
                            className="mvp-tender-detail__decision"
                        >
                            <div className="mvp-tender-detail__section-heading">
                                <div>
                                    <p>Быстрая оценка</p>
                                    <h2 id="tender-decision-title">
                                        Главное для решения
                                    </h2>
                                </div>
                                <span>Сверьте с первоисточником</span>
                            </div>
                            <dl className="mvp-tender-detail__highlights">
                                <DetailRow
                                    emphasis
                                    label="НМЦК"
                                    value={formatBudget(
                                        tender.budget_amount,
                                        tender.currency,
                                    )}
                                />
                                <DetailRow
                                    emphasis
                                    label="Срок подачи"
                                    missingLabel="Нет в данных ЕИС"
                                    tone={tender.deadline_at ? 'default' : 'warning'}
                                    value={formatDate(tender.deadline_at)}
                                />
                                <DetailRow
                                    label="Опубликован"
                                    value={formatDate(tender.published_at)}
                                />
                                <DetailRow
                                    label="Номер закупки"
                                    value={tender.reg_number}
                                />
                            </dl>
                            {!tender.deadline_at ? (
                                <InlineAlert
                                    title="Срок подачи не указан"
                                    tone="warning"
                                >
                                    Не считайте дату отсутствующей: откройте карточку
                                    ЕИС и проверьте актуальный срок вручную.
                                </InlineAlert>
                            ) : null}
                        </section>

                        {tender.match_reason ? (
                            <section
                                aria-labelledby="tender-match-title"
                                className="mvp-tender-detail__match"
                            >
                                <div>
                                    <p>Проверяемая причина</p>
                                    <h2 id="tender-match-title">
                                        Почему показан этот тендер
                                    </h2>
                                </div>
                                <p>{matchReasonLabel(tender.match_reason)}</p>
                                <div className="mvp-tender-detail__terms">
                                    {tender.match_reason.matched_terms.map((term) => (
                                        <span key={term}>{term}</span>
                                    ))}
                                </div>
                                {tender.match_reason.minus_keywords_checked.length >
                                0 ? (
                                    <p className="mvp-tender-detail__exclusions">
                                        Исключения не найдены:{' '}
                                        {tender.match_reason.minus_keywords_checked.join(
                                            ', ',
                                        )}
                                    </p>
                                ) : null}
                            </section>
                        ) : null}

                        <section
                            aria-labelledby="tender-info-title"
                            className="mvp-tender-detail__section"
                        >
                            <h2 id="tender-info-title">Данные закупки</h2>
                            <dl className="mvp-tender-detail__grid">
                                <DetailRow label="Заказчик" value={tender.customer} />
                                <DetailRow label="Регион" value={tender.region} />
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

                        <section
                            aria-labelledby="tender-contacts-title"
                            className="mvp-tender-detail__section"
                        >
                            <h2 id="tender-contacts-title">Контактная информация</h2>
                            <dl className="mvp-tender-detail__grid">
                                <DetailRow
                                    label="Контактное лицо"
                                    value={tender.contact_name}
                                />
                                <DetailRow
                                    label="Телефон"
                                    value={tender.contact_phone}
                                />
                                <DetailRow label="Email" value={tender.contact_email} />
                                <DetailRow
                                    label="Почтовый адрес"
                                    value={tender.postal_address}
                                />
                            </dl>
                        </section>

                        <section
                            aria-labelledby="tender-description-title"
                            className="mvp-tender-detail__section"
                        >
                            <h2 id="tender-description-title">Описание</h2>
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

                        <section
                            aria-labelledby="tender-attachments-title"
                            className="mvp-tender-detail__section"
                        >
                            <h2 id="tender-attachments-title">ТЗ и вложения</h2>
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
                                    RSS ЕИС не передала ссылки на ТЗ или вложения. Мы не
                                    создаём фальшивые PDF и не извлекаем защищённые
                                    документы.
                                </p>
                            )}
                        </section>
                    </div>

                    <aside
                        aria-label="Действия с тендером"
                        className="mvp-tender-detail__aside"
                    >
                        <section className="mvp-tender-detail__status-card">
                            <div className="mvp-tender-detail__status-heading">
                                <div>
                                    <p>Моя отметка</p>
                                    <h2>{statusLabel(tender.status)}</h2>
                                </div>
                                <Badge tone={statusTone(tender.status)}>
                                    {statusShortLabel(tender.status)}
                                </Badge>
                            </div>
                            <p>Эта отметка видна только вам и не меняет данные ЕИС.</p>
                            {tender.status === 'archived' ? (
                                <Button
                                    disabled={isUpdating}
                                    onClick={() => updateStatus('new')}
                                    variant="secondary"
                                >
                                    Вернуть в список
                                </Button>
                            ) : (
                                <div className="mvp-tender-detail__status-actions">
                                    <Button
                                        aria-pressed={tender.status === 'favorite'}
                                        disabled={isUpdating}
                                        onClick={() =>
                                            updateStatus(
                                                tender.status === 'favorite'
                                                    ? 'new'
                                                    : 'favorite',
                                            )
                                        }
                                        variant={
                                            tender.status === 'favorite'
                                                ? 'primary'
                                                : 'secondary'
                                        }
                                    >
                                        {tender.status === 'favorite'
                                            ? 'В избранном'
                                            : 'В избранное'}
                                    </Button>
                                    <Button
                                        aria-pressed={tender.status === 'potential'}
                                        disabled={isUpdating}
                                        onClick={() =>
                                            updateStatus(
                                                tender.status === 'potential'
                                                    ? 'new'
                                                    : 'potential',
                                            )
                                        }
                                        variant="ghost"
                                    >
                                        {tender.status === 'potential'
                                            ? 'Потенциальный'
                                            : 'Отметить потенциальным'}
                                    </Button>
                                    <Button
                                        aria-pressed={tender.status === 'dismissed'}
                                        disabled={isUpdating}
                                        onClick={() =>
                                            updateStatus(
                                                tender.status === 'dismissed'
                                                    ? 'new'
                                                    : 'dismissed',
                                            )
                                        }
                                        variant="ghost"
                                    >
                                        {tender.status === 'dismissed'
                                            ? 'Вернуть в новые'
                                            : 'Скрыть'}
                                    </Button>
                                    <Button
                                        disabled={isUpdating}
                                        onClick={() => updateStatus('archived')}
                                        variant="ghost"
                                    >
                                        Убрать из списка
                                    </Button>
                                </div>
                            )}
                            {actionError ? (
                                <p
                                    aria-live="polite"
                                    className="mvp-tender-detail__action-error"
                                >
                                    {actionError}
                                </p>
                            ) : null}
                        </section>

                        <section className="mvp-tender-detail__annotation">
                            <h2>Моя работа с закупкой</h2>
                            <p>
                                Заметка, теги и дата следующего действия видны только
                                вам.
                            </p>
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
                                    onChange={(event) =>
                                        setNextActionOn(event.target.value)
                                    }
                                    type="date"
                                    value={nextActionOn}
                                />
                            </label>
                            <Button disabled={saving} onClick={saveAnnotation}>
                                {saving ? 'Сохраняем…' : 'Сохранить заметку'}
                            </Button>
                        </section>

                        <GlassCard className="mvp-tender-detail__source" tone="quiet">
                            <p>Первоисточник</p>
                            <strong>{tender.source_label}</strong>
                            <a
                                href={tender.canonical_url}
                                rel="noreferrer"
                                target="_blank"
                            >
                                Открыть карточку источника →
                            </a>
                            <span>
                                Там проверяются сроки, документы и финальные условия.
                            </span>
                        </GlassCard>
                    </aside>
                </div>
            </AppShell>
        </>
    );
}

function DetailRow({
    label,
    value,
    emphasis = false,
    missingLabel = 'Не передано источником',
    tone = 'default',
}: {
    label: string;
    value: string | null;
    emphasis?: boolean;
    missingLabel?: string;
    tone?: 'default' | 'warning';
}) {
    return (
        <div
            className={`mvp-tender-detail__data-row ${emphasis ? 'is-emphasis' : ''} ${tone === 'warning' ? 'is-warning' : ''}`}
        >
            <dt>{label}</dt>
            <dd>{value ?? missingLabel}</dd>
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

function matchReasonLabel(reason: TenderMatchReason): string {
    const terms = reason.matched_terms.join(', ');

    return reason.mode === 'exact'
        ? `Найдена точная фраза: «${terms}»`
        : reason.mode === 'any'
          ? 'Совпало хотя бы одно слово из запроса.'
          : 'Совпали все слова из запроса.';
}

function statusLabel(status: TenderStatus): string {
    return {
        new: 'Новый тендер',
        favorite: 'В избранном',
        potential: 'Потенциальный',
        dismissed: 'Скрыт',
        archived: 'Убран из списка',
    }[status];
}

function statusShortLabel(status: TenderStatus): string {
    return {
        new: 'Новый',
        favorite: 'Избранное',
        potential: 'Потенциальный',
        dismissed: 'Скрыт',
        archived: 'Убран',
    }[status];
}

function statusTone(
    status: TenderStatus,
): 'accent' | 'success' | 'neutral' | 'warning' {
    const tones: Record<TenderStatus, 'accent' | 'success' | 'neutral' | 'warning'> = {
        new: 'accent',
        favorite: 'success',
        potential: 'warning',
        dismissed: 'neutral',
        archived: 'neutral',
    };

    return tones[status];
}
