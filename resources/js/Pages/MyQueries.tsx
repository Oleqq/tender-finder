import { Head, Link, usePage } from '@inertiajs/react';
import type { Dispatch, FormEvent, SetStateAction } from 'react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import {
    Badge,
    BottomSheet,
    Button,
    FieldError,
    GlassCard,
    InlineAlert,
} from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import type { PageProps } from '../types';

type QueryStatus = 'active' | 'paused' | 'frozen';

type QueryDto = {
    id: number;
    name: string;
    keywords: string[];
    minus_keywords: string[] | null;
    region: string | null;
    budget_min: string | null;
    budget_max: string | null;
    deadline_from: string | null;
    deadline_to: string | null;
    status: QueryStatus;
    monitoring_started_at: string | null;
};

type QueryFormValues = {
    name: string;
    keywords: string;
    minusKeywords: string;
    region: string;
    budgetMin: string;
    budgetMax: string;
    deadlineFrom: string;
    deadlineTo: string;
};

type QueryPayload = {
    name: string | null;
    keywords: string[];
    minus_keywords: string[];
    region: string | null;
    budget_min: string | null;
    budget_max: string | null;
    deadline_from: string | null;
    deadline_to: string | null;
};

type MyQueriesProps = {
    queries: QueryDto[];
};

const emptyQueryForm = (): QueryFormValues => ({
    name: '',
    keywords: '',
    minusKeywords: '',
    region: '',
    budgetMin: '',
    budgetMax: '',
    deadlineFrom: '',
    deadlineTo: '',
});

export default function MyQueries() {
    const { auth, queries: initialQueries } =
        usePage<PageProps<MyQueriesProps>>().props;
    const [queries, setQueries] = useState<QueryDto[]>(initialQueries);
    const [createForm, setCreateForm] = useState<QueryFormValues>(emptyQueryForm);
    const [editingQuery, setEditingQuery] = useState<QueryDto | null>(null);
    const [editForm, setEditForm] = useState<QueryFormValues>(emptyQueryForm);
    const [deleteCandidate, setDeleteCandidate] = useState<QueryDto | null>(null);
    const [createError, setCreateError] = useState('');
    const [editError, setEditError] = useState('');
    const [actionError, setActionError] = useState('');
    const [isCreating, setIsCreating] = useState(false);
    const [isSavingEdit, setIsSavingEdit] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const replaceQuery = (updatedQuery: QueryDto): void => {
        setQueries((current) =>
            current.map((query) =>
                query.id === updatedQuery.id ? updatedQuery : query,
            ),
        );
    };

    const createQuery = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();
        const payload = toQueryPayload(createForm);

        if (!payload) {
            setCreateError('Укажите хотя бы одно ключевое слово через запятую.');
            return;
        }

        setCreateError('');
        setActionError('');
        setIsCreating(true);

        try {
            const response = await window.axios.post<{ query: QueryDto }>(
                '/queries',
                payload,
            );
            setQueries((current) => [response.data.query, ...current]);
            setCreateForm(emptyQueryForm());
        } catch {
            setCreateError(
                'Не удалось создать мониторинг. Проверьте доступ и попробуйте ещё раз.',
            );
        } finally {
            setIsCreating(false);
        }
    };

    const openEdit = (query: QueryDto): void => {
        setActionError('');
        setEditError('');
        setEditingQuery(query);
        setEditForm(queryToForm(query));
    };

    const closeEdit = (): void => {
        if (!isSavingEdit) {
            setEditingQuery(null);
            setEditError('');
        }
    };

    const updateQuery = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        if (!editingQuery) {
            return;
        }

        const payload = toQueryPayload(editForm);

        if (!payload) {
            setEditError('Укажите хотя бы одно ключевое слово через запятую.');
            return;
        }

        setEditError('');
        setIsSavingEdit(true);

        try {
            const response = await window.axios.patch<{ query: QueryDto }>(
                `/queries/${editingQuery.id}`,
                payload,
            );
            replaceQuery(response.data.query);
            setEditingQuery(null);
        } catch {
            setEditError(
                'Не удалось сохранить изменения. Настройки не изменены — попробуйте ещё раз.',
            );
        } finally {
            setIsSavingEdit(false);
        }
    };

    const changeStatus = async (
        query: QueryDto,
        action: 'pause' | 'resume' | 'freeze',
    ): Promise<void> => {
        setActionError('');

        try {
            const response = await window.axios.post<{ query: QueryDto }>(
                `/queries/${query.id}/${action}`,
            );
            replaceQuery(response.data.query);
        } catch {
            setActionError(
                'Не удалось изменить состояние мониторинга. Ничего не потеряно — попробуйте ещё раз.',
            );
        }
    };

    const deleteQuery = async (): Promise<void> => {
        if (!deleteCandidate) {
            return;
        }

        setActionError('');
        setIsDeleting(true);

        try {
            await window.axios.delete(`/queries/${deleteCandidate.id}`);
            setQueries((current) =>
                current.filter((query) => query.id !== deleteCandidate.id),
            );
            setDeleteCandidate(null);
        } catch {
            setActionError(
                'Не удалось удалить мониторинг. Он остаётся без изменений — попробуйте ещё раз.',
            );
        } finally {
            setIsDeleting(false);
        }
    };

    const access = presentAccess(auth.access);
    const accessText = auth.access?.active_query_limit
        ? `${queries.filter((query) => query.status === 'active').length} из ${auth.access.active_query_limit} активных`
        : 'Лимит появится после активации доступа';
    const canCreate =
        ['trialing', 'active'].includes(auth.access?.state ?? '') &&
        auth.access?.active_query_limit !== null;

    return (
        <>
            <Head title="Мои мониторинги" />
            <AppShell
                activeNav="/tenders"
                eyebrow="Защищённый раздел"
                role={auth.user?.role ?? 'subscriber'}
                title="Мониторинги"
            >
                <GlassCard className="query-access page-enter" tone="quiet">
                    <span className="query-access__icon">
                        <Icon name="layers" size={19} />
                    </span>
                    <div>
                        <p>Лимит мониторингов</p>
                        <strong>{accessText}</strong>
                    </div>
                    <Badge tone={access.tone}>{access.badge}</Badge>
                </GlassCard>

                {canCreate ? (
                    <GlassCard className="query-create page-enter page-enter--delay">
                        <div className="section-heading">
                            <div>
                                <p>Новый мониторинг</p>
                                <h2>Что искать?</h2>
                            </div>
                        </div>
                        <form onSubmit={createQuery}>
                            <QueryFields
                                form={createForm}
                                onChange={updateForm(setCreateForm)}
                            />
                            <p className="query-create__hint">
                                Запятая разделяет слова. Keywords обязательны,
                                минус-слова исключают совпадение; неизвестные RSS-поля
                                не угадываются.
                            </p>
                            {createError ? (
                                <FieldError>{createError}</FieldError>
                            ) : null}
                            <Button disabled={isCreating} icon="check" type="submit">
                                {isCreating ? 'Создаём…' : 'Включить мониторинг'}
                            </Button>
                        </form>
                    </GlassCard>
                ) : (
                    <InlineAlert title="Мониторинги пока недоступны" tone="neutral">
                        Доступ ещё не активирован.{' '}
                        <Link href="/plans">Подробнее о текущем статусе</Link>
                    </InlineAlert>
                )}

                {actionError ? (
                    <InlineAlert title="Можно повторить" tone="warning">
                        {actionError}
                    </InlineAlert>
                ) : null}

                <section className="query-list page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Сохранённые настройки</p>
                            <h2>Ваши мониторинги</h2>
                        </div>
                    </div>
                    {queries.length === 0 ? (
                        <GlassCard tone="quiet">
                            <p>
                                Пока нет сохранённых мониторингов. Создайте первый,
                                когда доступ станет активным.
                            </p>
                        </GlassCard>
                    ) : (
                        queries.map((query) => {
                            const details = queryDetails(query);

                            return (
                                <GlassCard
                                    as="article"
                                    className="query-card"
                                    key={query.id}
                                >
                                    <div>
                                        <Badge
                                            tone={
                                                query.status === 'active'
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                        >
                                            {statusLabel(query.status)}
                                        </Badge>
                                        <h3>{query.name}</h3>
                                        <p>{query.keywords.join(' · ')}</p>
                                        {details ? <p>{details}</p> : null}
                                    </div>
                                    <div className="query-card__actions">
                                        {query.status === 'active' ? (
                                            <Button
                                                onClick={() =>
                                                    changeStatus(query, 'pause')
                                                }
                                                size="sm"
                                                variant="secondary"
                                            >
                                                Пауза
                                            </Button>
                                        ) : (
                                            <Button
                                                onClick={() =>
                                                    changeStatus(query, 'resume')
                                                }
                                                size="sm"
                                                variant="secondary"
                                            >
                                                Возобновить
                                            </Button>
                                        )}
                                        <Button
                                            onClick={() => openEdit(query)}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            Изменить
                                        </Button>
                                        {query.status !== 'frozen' ? (
                                            <Button
                                                onClick={() =>
                                                    changeStatus(query, 'freeze')
                                                }
                                                size="sm"
                                                variant="ghost"
                                            >
                                                Заморозить
                                            </Button>
                                        ) : null}
                                        <Button
                                            onClick={() => {
                                                setActionError('');
                                                setDeleteCandidate(query);
                                            }}
                                            size="sm"
                                            variant="danger"
                                        >
                                            Удалить
                                        </Button>
                                    </div>
                                </GlassCard>
                            );
                        })
                    )}
                </section>
            </AppShell>

            <BottomSheet
                onClose={closeEdit}
                open={editingQuery !== null}
                title="Изменить мониторинг"
            >
                <form className="query-edit-form" onSubmit={updateQuery}>
                    <QueryFields form={editForm} onChange={updateForm(setEditForm)} />
                    {editError ? <FieldError>{editError}</FieldError> : null}
                    <Button
                        className="sheet-action"
                        disabled={isSavingEdit}
                        icon="check"
                        type="submit"
                    >
                        {isSavingEdit ? 'Сохраняем…' : 'Сохранить изменения'}
                    </Button>
                    <Button
                        className="sheet-action"
                        disabled={isSavingEdit}
                        onClick={closeEdit}
                        variant="secondary"
                    >
                        Отмена
                    </Button>
                </form>
            </BottomSheet>

            <BottomSheet
                onClose={() => !isDeleting && setDeleteCandidate(null)}
                open={deleteCandidate !== null}
                title="Удалить мониторинг?"
            >
                <p className="sheet-description">
                    «{deleteCandidate?.name}» перестанет участвовать в подборе. Это
                    действие можно будет создать заново, но восстановить карточку
                    нельзя.
                </p>
                <Button
                    className="sheet-action"
                    disabled={isDeleting}
                    onClick={deleteQuery}
                    variant="danger"
                >
                    {isDeleting ? 'Удаляем…' : 'Удалить мониторинг'}
                </Button>
                <Button
                    className="sheet-action"
                    disabled={isDeleting}
                    onClick={() => setDeleteCandidate(null)}
                    variant="secondary"
                >
                    Отмена
                </Button>
            </BottomSheet>
        </>
    );
}

function QueryFields({
    form,
    onChange,
}: {
    form: QueryFormValues;
    onChange: (field: keyof QueryFormValues, value: string) => void;
}) {
    return (
        <>
            <label className="form-field">
                <span>Название мониторинга</span>
                <input
                    onChange={(event) => onChange('name', event.target.value)}
                    placeholder="например, Поддержка сайта"
                    value={form.name}
                />
            </label>
            <label className="form-field">
                <span>Ключевые слова</span>
                <input
                    onChange={(event) => onChange('keywords', event.target.value)}
                    placeholder="например, сайт, поддержка"
                    value={form.keywords}
                />
            </label>
            <label className="form-field">
                <span>Минус-слова</span>
                <input
                    onChange={(event) => onChange('minusKeywords', event.target.value)}
                    placeholder="например, строительство"
                    value={form.minusKeywords}
                />
            </label>
            <label className="form-field">
                <span>Регион</span>
                <input
                    onChange={(event) => onChange('region', event.target.value)}
                    placeholder="например, Москва"
                    value={form.region}
                />
            </label>
            <div className="query-create__grid">
                <label className="form-field">
                    <span>Бюджет от, ₽</span>
                    <input
                        inputMode="decimal"
                        min="0"
                        onChange={(event) => onChange('budgetMin', event.target.value)}
                        placeholder="0"
                        type="number"
                        value={form.budgetMin}
                    />
                </label>
                <label className="form-field">
                    <span>Бюджет до, ₽</span>
                    <input
                        inputMode="decimal"
                        min={form.budgetMin || '0'}
                        onChange={(event) => onChange('budgetMax', event.target.value)}
                        placeholder="Без лимита"
                        type="number"
                        value={form.budgetMax}
                    />
                </label>
                <label className="form-field">
                    <span>Дедлайн от</span>
                    <input
                        onChange={(event) =>
                            onChange('deadlineFrom', event.target.value)
                        }
                        type="date"
                        value={form.deadlineFrom}
                    />
                </label>
                <label className="form-field">
                    <span>Дедлайн до</span>
                    <input
                        min={form.deadlineFrom || undefined}
                        onChange={(event) => onChange('deadlineTo', event.target.value)}
                        type="date"
                        value={form.deadlineTo}
                    />
                </label>
            </div>
        </>
    );
}

function updateForm(
    setForm: Dispatch<SetStateAction<QueryFormValues>>,
): (field: keyof QueryFormValues, value: string) => void {
    return (field, value): void => {
        setForm((current) => ({ ...current, [field]: value }));
    };
}

function queryToForm(query: QueryDto): QueryFormValues {
    return {
        name: query.name,
        keywords: query.keywords.join(', '),
        minusKeywords: query.minus_keywords?.join(', ') ?? '',
        region: query.region ?? '',
        budgetMin: query.budget_min ?? '',
        budgetMax: query.budget_max ?? '',
        deadlineFrom: query.deadline_from ?? '',
        deadlineTo: query.deadline_to ?? '',
    };
}

function toQueryPayload(form: QueryFormValues): QueryPayload | null {
    const keywords = splitKeywords(form.keywords);

    if (keywords.length === 0) {
        return null;
    }

    return {
        name: form.name.trim() || null,
        keywords,
        minus_keywords: splitKeywords(form.minusKeywords),
        region: form.region.trim() || null,
        budget_min: form.budgetMin || null,
        budget_max: form.budgetMax || null,
        deadline_from: form.deadlineFrom || null,
        deadline_to: form.deadlineTo || null,
    };
}

function splitKeywords(value: string): string[] {
    return value
        .split(',')
        .map((keyword) => keyword.trim())
        .filter(Boolean);
}

function queryDetails(query: QueryDto): string | null {
    const details = [
        query.region,
        budgetDetail(query.budget_min, query.budget_max),
        dateDetail(query.deadline_from, query.deadline_to),
    ].filter(Boolean);

    return details.length > 0 ? details.join(' · ') : null;
}

function budgetDetail(min: string | null, max: string | null): string | null {
    if (!min && !max) {
        return null;
    }

    if (min && max) {
        return `${formatMoney(min)}–${formatMoney(max)} ₽`;
    }

    return min ? `от ${formatMoney(min)} ₽` : `до ${formatMoney(max ?? '')} ₽`;
}

function dateDetail(from: string | null, to: string | null): string | null {
    if (!from && !to) {
        return null;
    }

    return from && to
        ? `дедлайн ${from}–${to}`
        : from
          ? `дедлайн от ${from}`
          : `дедлайн до ${to ?? ''}`;
}

function formatMoney(value: string): string {
    return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(
        Number(value),
    );
}

function statusLabel(status: QueryStatus): string {
    return {
        active: 'Активен',
        paused: 'На паузе',
        frozen: 'Заморожен',
    }[status];
}
