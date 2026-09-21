import { Head, Link, usePage } from '@inertiajs/react';
import type { Dispatch, FormEvent, SetStateAction } from 'react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { MonitoringWizard } from '../Components/MonitoringWizard';
import { Icon } from '../Components/Icon';
import {
    SavedSearchRunHistory,
    type SavedSearchRunResult,
} from '../Components/SavedSearchRunHistory';
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
    last_run_at: string | null;
    last_run: QueryRunSummary | null;
    filters: Record<string, unknown> | null;
    source_statuses?: SourceStatus[];
};

type SourceStatus = {
    source: 'eis_rss' | 'rostender';
    state: 'ok' | 'empty' | 'error' | 'queued' | 'paused' | 'pending';
    message: string;
    last_success_at: string | null;
    last_success_items_seen: number | null;
    last_failure_at: string | null;
    next_attempt_at: string | null;
};

type QueryRunSummary = {
    items_seen: number;
    items_matched: number;
    items_created: number;
    pages_requested: number;
    pages_loaded: number;
    partially_loaded: boolean;
};

type TenderDto = {
    id: number;
    title: string;
    customer: string | null;
    region: string | null;
    budget_amount: string | null;
    currency: string;
    published_at: string | null;
    deadline_at: string | null;
    canonical_url: string;
};

type QueryRunResponse = {
    queued?: boolean;
    preview?: QueryRunSummary;
    tenders?: TenderDto[];
    query: QueryDto;
};

type QueryRunView = {
    queryName: string;
    caption: string;
    onlyNew: boolean;
    summary: QueryRunSummary & { new_count?: number };
    tenders: TenderDto[];
};

type QueryFormValues = {
    name: string;
    keywords: string;
    minusKeywords: string;
    excludedCustomers: string;
    region: string;
    budgetMin: string;
    budgetMax: string;
    deadlineFrom: string;
    deadlineTo: string;
    rostenderTemplateId: string | null;
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
    filters?: Record<string, unknown> | null;
};

type MyQueriesProps = {
    queries: QueryDto[];
    rostenderTemplates: Array<{ id: number; name: string }>;
};

const emptyQueryForm = (): QueryFormValues => ({
    name: '',
    keywords: '',
    minusKeywords: '',
    excludedCustomers: '',
    region: '',
    budgetMin: '',
    budgetMax: '',
    deadlineFrom: '',
    deadlineTo: '',
    rostenderTemplateId: '',
});

export default function MyQueries() {
    const {
        auth,
        queries: initialQueries,
        rostenderTemplates,
    } = usePage<PageProps<MyQueriesProps>>().props;
    const [queries, setQueries] = useState<QueryDto[]>(initialQueries);
    const [createForm, setCreateForm] = useState<QueryFormValues>(emptyQueryForm);
    const [editingQuery, setEditingQuery] = useState<QueryDto | null>(null);
    const [duplicatingQuery, setDuplicatingQuery] = useState<QueryDto | null>(null);
    const [editForm, setEditForm] = useState<QueryFormValues>(emptyQueryForm);
    const [deleteCandidate, setDeleteCandidate] = useState<QueryDto | null>(null);
    const [createError, setCreateError] = useState('');
    const [editError, setEditError] = useState('');
    const [actionError, setActionError] = useState('');
    const [actionNotice, setActionNotice] = useState('');
    const [isCreating, setIsCreating] = useState(false);
    const [isSavingEdit, setIsSavingEdit] = useState(false);
    const [isDuplicating, setIsDuplicating] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [runningQueryId, setRunningQueryId] = useState<number | null>(null);
    const [historyQueryId, setHistoryQueryId] = useState<number | null>(null);
    const [runView, setRunView] = useState<QueryRunView | null>(null);

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
        setActionNotice('');
        setIsCreating(true);

        try {
            const response = await window.axios.post<{ query: QueryDto }>(
                '/queries',
                payload,
            );
            setQueries((current) => [response.data.query, ...current]);
            setCreateForm(emptyQueryForm());
            await runQuery(response.data.query);
        } catch (error) {
            setCreateError(
                requestErrorMessage(
                    error,
                    'Не удалось создать мониторинг. Проверьте доступ и попробуйте ещё раз.',
                ),
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

    const openDuplicate = (query: QueryDto): void => {
        setActionError('');
        setEditError('');
        setDuplicatingQuery(query);
        setEditForm({
            ...queryToForm(query),
            name: query.name + ' — копия',
        });
    };

    const closeDuplicate = (): void => {
        if (!isDuplicating) {
            setDuplicatingQuery(null);
            setEditError('');
        }
    };

    const duplicateQuery = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        if (!duplicatingQuery) return;

        const payload = toQueryPayload(editForm);
        if (!payload) {
            setEditError('Укажите хотя бы одно ключевое слово через запятую.');
            return;
        }

        setEditError('');
        setIsDuplicating(true);

        try {
            const response = await window.axios.post<{ query: QueryDto }>('/queries', {
                ...payload,
                filters: mergedFilters(duplicatingQuery, payload),
            });
            setQueries((current) => [response.data.query, ...current]);
            setDuplicatingQuery(null);
        } catch (error) {
            setEditError(
                requestErrorMessage(
                    error,
                    'Не удалось создать копию. Проверьте лимит активных мониторингов.',
                ),
            );
        } finally {
            setIsDuplicating(false);
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
                { ...payload, filters: mergedFilters(editingQuery, payload) },
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

    async function runQuery(query: QueryDto): Promise<void> {
        setActionError('');
        setRunningQueryId(query.id);

        try {
            const response = await window.axios.post<QueryRunResponse>(
                `/queries/${query.id}/run`,
            );
            replaceQuery(response.data.query);

            if (response.data.queued) {
                setActionNotice(
                    'Проверка RosTender поставлена в очередь. Карточки появятся в ленте после синхронизации.',
                );
                return;
            }

            if (!response.data.preview || !response.data.tenders) {
                throw new Error('Unexpected monitoring response');
            }
            setRunView({
                queryName: response.data.query.name,
                caption: 'Только что выполненный запуск',
                onlyNew: false,
                summary: response.data.preview,
                tenders: response.data.tenders,
            });
        } catch (error) {
            setActionError(
                requestErrorMessage(
                    error,
                    'Не удалось запустить мониторинг. Попробуйте позже.',
                ),
            );
        } finally {
            setRunningQueryId(null);
        }
    }

    const openHistoricalRun = (
        query: QueryDto,
        result: SavedSearchRunResult<TenderDto>,
    ): void => {
        setRunView({
            queryName: query.name,
            caption: result.only_new
                ? 'Новые относительно предыдущего запуска'
                : `Сохранённый запуск от ${formatDateTime(result.run.created_at)}`,
            onlyNew: result.only_new,
            summary: result.run,
            tenders: result.tenders,
        });
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
    const canRunManually = canCreate;

    return (
        <>
            <Head title="Мои мониторинги" />
            <AppShell
                activeNav="/tenders"
                className="queries-page"
                eyebrow="Защищённый раздел"
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
                        <MonitoringWizard
                            fields={(step) => (
                                <QueryFields
                                    form={createForm}
                                    onChange={updateForm(setCreateForm)}
                                    rostenderTemplates={rostenderTemplates}
                                    step={step}
                                />
                            )}
                            payload={toQueryPayload(createForm)}
                            saving={isCreating}
                            error={createError}
                            onSubmit={createQuery}
                        />
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

                {actionNotice ? (
                    <InlineAlert title="Проверка запланирована" tone="success">
                        {actionNotice}
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
                        <GlassCard className="query-empty" tone="quiet">
                            <p>
                                Пока нет сохранённых мониторингов. Создайте первый,
                                когда доступ станет активным.
                            </p>
                        </GlassCard>
                    ) : (
                        queries.map((query) => {
                            const details = queryDetails(query, rostenderTemplates);

                            return (
                                <GlassCard
                                    as="article"
                                    className="query-card"
                                    key={query.id}
                                >
                                    <div className="query-card__copy">
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
                                    <MonitoringHealth query={query} />
                                    <div className="query-card__actions">
                                        {canRunManually ? (
                                            <Button
                                                disabled={runningQueryId !== null}
                                                onClick={() => runQuery(query)}
                                                size="sm"
                                            >
                                                {runningQueryId === query.id
                                                    ? 'Запускаем…'
                                                    : 'Проверить сейчас'}
                                            </Button>
                                        ) : null}
                                        <details className="query-card__manage">
                                            <summary>Управление</summary>
                                            <div>
                                                {canRunManually ? (
                                                    <Button
                                                        onClick={() =>
                                                            setHistoryQueryId(
                                                                (current) =>
                                                                    current === query.id
                                                                        ? null
                                                                        : query.id,
                                                            )
                                                        }
                                                        size="sm"
                                                        variant="secondary"
                                                    >
                                                        {historyQueryId === query.id
                                                            ? 'Скрыть историю'
                                                            : 'История запусков'}
                                                    </Button>
                                                ) : null}
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
                                                            changeStatus(
                                                                query,
                                                                'resume',
                                                            )
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
                                                <Button
                                                    disabled={!canCreate}
                                                    onClick={() => openDuplicate(query)}
                                                    size="sm"
                                                    variant="secondary"
                                                >
                                                    Создать копию
                                                </Button>
                                                {query.status !== 'frozen' ? (
                                                    <Button
                                                        onClick={() =>
                                                            changeStatus(
                                                                query,
                                                                'freeze',
                                                            )
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
                                        </details>
                                    </div>
                                    {historyQueryId === query.id ? (
                                        <SavedSearchRunHistory<TenderDto>
                                            key={`${query.id}-${query.last_run_at ?? 'empty'}`}
                                            onOpenRun={(result) =>
                                                openHistoricalRun(query, result)
                                            }
                                            queryId={query.id}
                                        />
                                    ) : null}
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
                    <QueryFields
                        form={editForm}
                        onChange={updateForm(setEditForm)}
                        rostenderTemplates={rostenderTemplates}
                    />
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
                onClose={closeDuplicate}
                open={duplicatingQuery !== null}
                title="Новый мониторинг из копии"
            >
                <form className="query-edit-form" onSubmit={duplicateQuery}>
                    <p className="sheet-description">
                        Все условия источника скопированы. Измените название и основные
                        ограничения перед сохранением.
                    </p>
                    <QueryFields
                        form={editForm}
                        onChange={updateForm(setEditForm)}
                        rostenderTemplates={rostenderTemplates}
                    />
                    {editError ? <FieldError>{editError}</FieldError> : null}
                    <Button
                        className="sheet-action"
                        disabled={isDuplicating}
                        icon="check"
                        type="submit"
                    >
                        {isDuplicating ? 'Создаём копию…' : 'Создать мониторинг'}
                    </Button>
                    <Button
                        className="sheet-action"
                        disabled={isDuplicating}
                        onClick={closeDuplicate}
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

            <BottomSheet
                onClose={() => setRunView(null)}
                open={runView !== null}
                title={runView?.queryName ?? 'Результаты запуска'}
            >
                {runView ? (
                    <QueryRunResults onClose={() => setRunView(null)} run={runView} />
                ) : null}
            </BottomSheet>
        </>
    );
}

function QueryRunSummaryCard({ query }: { query: QueryDto }) {
    if (!query.last_run_at || !query.last_run) {
        return (
            <div className="query-card__run query-card__run--empty">
                <Icon name="search" size={18} />
                <p>Первый поиск ещё не завершён. Нажмите «Проверить сейчас».</p>
            </div>
        );
    }

    return (
        <div className="query-card__run">
            <div className="query-card__run-heading">
                <span>Последний запуск</span>
                <time dateTime={query.last_run_at}>
                    {formatDateTime(query.last_run_at)}
                </time>
            </div>
            <dl className="query-card__run-stats">
                <div>
                    <dt>Проверено</dt>
                    <dd>{query.last_run.items_seen}</dd>
                </div>
                <div>
                    <dt>Найдено</dt>
                    <dd>{query.last_run.items_matched}</dd>
                </div>
                <div>
                    <dt>Добавлено</dt>
                    <dd>{query.last_run.items_created}</dd>
                </div>
                <div>
                    <dt>Страницы</dt>
                    <dd>
                        {query.last_run.pages_loaded}/{query.last_run.pages_requested}
                    </dd>
                </div>
            </dl>
            {query.last_run.partially_loaded ? (
                <p className="query-card__run-warning">
                    ЕИС отдала не все запрошенные страницы.
                </p>
            ) : null}
        </div>
    );
}

function MonitoringHealth({ query }: { query: QueryDto }) {
    const statuses = query.source_statuses ?? [];
    const requiresAttention =
        statuses.length === 0 ||
        statuses.some((status) =>
            ['error', 'queued', 'paused', 'pending'].includes(status.state),
        );
    const summary = monitoringHealthSummary(statuses);

    return (
        <details className="query-card__health" open={requiresAttention}>
            <summary>
                <span>Работа мониторинга</span>
                <Badge tone={summary.tone}>{summary.label}</Badge>
            </summary>
            <div className="query-card__health-content">
                <QueryRunSummaryCard query={query} />
                <SourceStatusCards statuses={statuses} />
            </div>
        </details>
    );
}

function monitoringHealthSummary(statuses: SourceStatus[]): {
    label: string;
    tone: 'neutral' | 'accent' | 'success' | 'warning' | 'danger';
} {
    if (statuses.length === 0) {
        return { label: 'Первый запуск ожидается', tone: 'neutral' };
    }

    const state =
        statuses.find((status) => status.state === 'error')?.state ??
        statuses.find((status) => status.state === 'queued')?.state ??
        statuses.find((status) => status.state === 'pending')?.state ??
        statuses.find((status) => status.state === 'paused')?.state ??
        statuses[0].state;

    return {
        label: sourceStateLabel(state),
        tone: sourceTone(state),
    };
}

function SourceStatusCards({ statuses }: { statuses: SourceStatus[] }) {
    if (statuses.length === 0) {
        return (
            <div className="source-status source-status--pending">
                <strong>Состояние источника появится после первого запуска.</strong>
                <p>
                    Пока нельзя делать вывод, есть ли новые совпадения: источник ещё не
                    подтвердил ответ для этого мониторинга.
                </p>
            </div>
        );
    }

    return (
        <div className="source-statuses">
            {statuses.map((status) => (
                <section
                    className={`source-status source-status--${status.state}`}
                    key={status.source}
                >
                    <div className="source-status__heading">
                        <strong>{sourceLabel(status.source)}</strong>
                        <Badge tone={sourceTone(status.state)}>
                            {sourceStateLabel(status.state)}
                        </Badge>
                    </div>
                    <p>{status.message}</p>
                    <dl className="source-status__times">
                        <div>
                            <dt>Последний успех</dt>
                            <dd>
                                {status.last_success_at
                                    ? formatDateTime(status.last_success_at)
                                    : 'ещё не было'}
                            </dd>
                        </div>
                        <div>
                            <dt>Последняя ошибка</dt>
                            <dd>
                                {status.last_failure_at
                                    ? formatDateTime(status.last_failure_at)
                                    : 'не было'}
                            </dd>
                        </div>
                        <div>
                            <dt>Следующая попытка</dt>
                            <dd>
                                {status.next_attempt_at
                                    ? formatDateTime(status.next_attempt_at)
                                    : 'не запланирована'}
                            </dd>
                        </div>
                    </dl>
                </section>
            ))}
        </div>
    );
}

function sourceLabel(source: SourceStatus['source']): string {
    return source === 'rostender' ? 'RosTender' : 'ЕИС';
}

function sourceStateLabel(state: SourceStatus['state']): string {
    return {
        ok: 'Ответ получен',
        empty: 'Новых записей нет',
        error: 'Источник недоступен',
        queued: 'Ожидает очереди',
        paused: 'Остановлен',
        pending: 'Первый опрос',
    }[state];
}

function sourceTone(
    state: SourceStatus['state'],
): 'neutral' | 'success' | 'warning' | 'danger' {
    return state === 'ok' || state === 'empty'
        ? 'success'
        : state === 'error'
          ? 'danger'
          : state === 'queued' || state === 'pending'
            ? 'warning'
            : 'neutral';
}

function QueryRunResults({ run, onClose }: { run: QueryRunView; onClose: () => void }) {
    return (
        <div className="query-run-results">
            <div className="query-run-results__summary">
                <div>
                    <span>{run.caption}</span>
                    <strong>
                        {run.onlyNew
                            ? (run.summary.new_count ?? 0)
                            : run.summary.items_matched}{' '}
                        совпадений
                    </strong>
                </div>
                <Badge tone={run.summary.partially_loaded ? 'warning' : 'success'}>
                    {run.summary.pages_loaded}/{run.summary.pages_requested} страниц
                </Badge>
            </div>
            {run.summary.new_count !== undefined ? (
                <p className="query-run-results__note">
                    Новых относительно предыдущего запуска: {run.summary.new_count}.
                </p>
            ) : null}
            {run.tenders.length === 0 ? (
                <div className="query-run-results__empty">
                    <Icon name="search" size={22} />
                    <strong>Подходящих карточек нет</strong>
                    <p>Условия сохранены — следующий запуск можно повторить позже.</p>
                </div>
            ) : (
                <div className="query-run-results__list">
                    {run.tenders.map((tender) => (
                        <article className="query-run-result" key={tender.id}>
                            <div className="query-run-result__heading">
                                <Badge tone="accent">
                                    {tender.budget_amount
                                        ? formatTenderMoney(
                                              tender.budget_amount,
                                              tender.currency,
                                          )
                                        : 'Цена не указана'}
                                </Badge>
                                {tender.deadline_at ? (
                                    <span>до {formatDate(tender.deadline_at)}</span>
                                ) : null}
                            </div>
                            <h3>
                                <Link href={`/local/mvp/tenders/${tender.id}`}>
                                    {tender.title}
                                </Link>
                            </h3>
                            {tender.customer ? <p>{tender.customer}</p> : null}
                            <div className="query-run-result__meta">
                                {tender.region ? <span>{tender.region}</span> : null}
                                {tender.published_at ? (
                                    <span>
                                        опубликован {formatDate(tender.published_at)}
                                    </span>
                                ) : null}
                            </div>
                            <div className="query-run-result__links">
                                <Link href={`/local/mvp/tenders/${tender.id}`}>
                                    Открыть карточку
                                </Link>
                                <a
                                    href={tender.canonical_url}
                                    rel="noreferrer"
                                    target="_blank"
                                >
                                    Первоисточник
                                </a>
                            </div>
                        </article>
                    ))}
                </div>
            )}
            <Button className="sheet-action" onClick={onClose} variant="secondary">
                Закрыть
            </Button>
        </div>
    );
}

function QueryFields({
    form,
    onChange,
    rostenderTemplates,
    step,
}: {
    step?: number;
    form: QueryFormValues;
    onChange: (field: keyof QueryFormValues, value: string) => void;
    rostenderTemplates: Array<{ id: number; name: string }>;
}) {
    return (
        <>
            <div hidden={step !== undefined && step !== 3}>
                <label className="form-field">
                    <span>Площадка для мониторинга</span>
                    <select
                        onChange={(event) =>
                            onChange('rostenderTemplateId', event.target.value)
                        }
                        value={form.rostenderTemplateId ?? ''}
                    >
                        <option value="">ЕИС · поиск по ключевым словам</option>
                        {rostenderTemplates.map((template) => (
                            <option key={template.id} value={String(template.id)}>
                                RosTender · {template.name}
                            </option>
                        ))}
                    </select>
                </label>
                {rostenderTemplates.length > 0 ? (
                    <p className="query-create__hint">
                        Шаблон задаёт удалённую выдачу RosTender; ваши ключевые слова и
                        фильтры дополнительно отберут подходящие карточки в
                        TenderFinder.
                    </p>
                ) : null}
            </div>
            <div hidden={step !== undefined && step !== 0}>
                <p>
                    Опишите нишу ключевыми словами через запятую. Все указанные слова
                    должны встретиться в карточке.
                </p>
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
                        onChange={(event) =>
                            onChange('minusKeywords', event.target.value)
                        }
                        placeholder="например, строительство"
                        value={form.minusKeywords}
                    />
                </label>
                {form.excludedCustomers || step === undefined ? (
                    <label className="form-field">
                        <span>Исключённые заказчики — по одному на строку</span>
                        <textarea
                            value={form.excludedCustomers}
                            onChange={(event) =>
                                onChange('excludedCustomers', event.target.value)
                            }
                        />
                        <small>
                            Удалите строку, чтобы снова получать закупки заказчика.
                        </small>
                    </label>
                ) : null}
            </div>
            <div hidden={step !== undefined && step !== 1}>
                <p>Оставьте поле пустым, если готовы работать по всей России.</p>
                <label className="form-field">
                    <span>Регион</span>
                    <input
                        onChange={(event) => onChange('region', event.target.value)}
                        placeholder="например, Москва"
                        value={form.region}
                    />
                </label>
            </div>
            <div hidden={step !== undefined && step !== 2}>
                <div className="query-create__grid">
                    <label className="form-field">
                        <span>Бюджет от, ₽</span>
                        <input
                            inputMode="decimal"
                            min="0"
                            onChange={(event) =>
                                onChange('budgetMin', event.target.value)
                            }
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
                            onChange={(event) =>
                                onChange('budgetMax', event.target.value)
                            }
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
                            onChange={(event) =>
                                onChange('deadlineTo', event.target.value)
                            }
                            type="date"
                            value={form.deadlineTo}
                        />
                    </label>
                </div>
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
        excludedCustomers: Array.isArray(query.filters?.excluded_customers)
            ? query.filters.excluded_customers.join('\n')
            : '',
        region: query.region ?? '',
        budgetMin: query.budget_min ?? '',
        budgetMax: query.budget_max ?? '',
        deadlineFrom: query.deadline_from ?? '',
        deadlineTo: query.deadline_to ?? '',
        rostenderTemplateId: rostenderTemplateId(query),
    };
}

function toQueryPayload(form: QueryFormValues): QueryPayload | null {
    const keywords = splitKeywords(form.keywords);

    if (keywords.length === 0) {
        return null;
    }

    const source =
        form.rostenderTemplateId === null
            ? undefined
            : {
                  stage_application: true,
                  rostender_template_id:
                      form.rostenderTemplateId === ''
                          ? null
                          : Number(form.rostenderTemplateId),
              };

    return {
        name: form.name.trim() || null,
        keywords,
        minus_keywords: splitKeywords(form.minusKeywords),
        region: form.region.trim() || null,
        budget_min: form.budgetMin || null,
        budget_max: form.budgetMax || null,
        deadline_from: form.deadlineFrom || null,
        deadline_to: form.deadlineTo || null,
        filters: {
            ...(source ? { source } : {}),
            excluded_customers: form.excludedCustomers
                .split('\n')
                .map((value) => value.trim())
                .filter(Boolean),
        },
    };
}

function splitKeywords(value: string): string[] {
    return value
        .split(',')
        .map((keyword) => keyword.trim())
        .filter(Boolean);
}

function queryDetails(
    query: QueryDto,
    rostenderTemplates: Array<{ id: number; name: string }>,
): string | null {
    const details = [
        rostenderTemplateName(query, rostenderTemplates),
        query.region,
        budgetDetail(query.budget_min, query.budget_max),
        dateDetail(query.deadline_from, query.deadline_to),
    ].filter(Boolean);

    return details.length > 0 ? details.join(' · ') : null;
}

function rostenderTemplateId(query: QueryDto): string | null {
    const source = query.filters?.source;

    if (!source || typeof source !== 'object') {
        return null;
    }

    const value = (source as Record<string, unknown>).rostender_template_id;

    return typeof value === 'number' && value > 0 ? String(value) : null;
}

function rostenderTemplateName(
    query: QueryDto,
    templates: Array<{ id: number; name: string }>,
): string | null {
    const id = rostenderTemplateId(query);
    const template = templates.find((item) => String(item.id) === id);

    return template
        ? `RosTender · ${template.name}`
        : id
          ? 'RosTender · шаблон подключён'
          : null;
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

function formatTenderMoney(value: string, currency: string): string {
    return `${formatMoney(value)} ${currency === 'RUB' ? '₽' : currency}`;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function requestErrorMessage(error: unknown, fallback: string): string {
    const response = (
        error as {
            response?: {
                data?: {
                    message?: unknown;
                    errors?: Record<string, unknown>;
                };
            };
        }
    ).response;
    const errors = response?.data?.errors;

    if (errors) {
        for (const value of Object.values(errors)) {
            if (typeof value === 'string') {
                return value;
            }

            if (Array.isArray(value) && typeof value[0] === 'string') {
                return value[0];
            }
        }
    }

    return typeof response?.data?.message === 'string'
        ? response.data.message
        : fallback;
}

function statusLabel(status: QueryStatus): string {
    return {
        active: 'Активен',
        paused: 'На паузе',
        frozen: 'Заморожен',
    }[status];
}

function mergedFilters(
    query: QueryDto,
    payload: QueryPayload,
): Record<string, unknown> {
    const existing = query.filters ?? {};
    const originalSource =
        typeof existing.source === 'object' && existing.source !== null
            ? existing.source
            : {};
    const update = payload.filters ?? {};
    const changedSource =
        typeof update.source === 'object' && update.source !== null
            ? (update.source as Record<string, unknown>)
            : {};
    return {
        ...existing,
        ...update,
        source: {
            ...originalSource,
            rostender_template_id: changedSource.rostender_template_id ?? null,
        },
    };
}
