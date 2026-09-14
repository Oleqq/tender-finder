import { Head, Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import { TenderFeedbackActions } from '../Components/TenderFeedbackActions';
import {
    AssigneeSelect,
    type TeamScope,
    WorkspacePicker,
} from '../Components/WorkspacePicker';
import { Icon } from '../Components/Icon';
import {
    Badge,
    Button,
    EmptyState,
    FilterChip,
    GlassCard,
    SearchInput,
    SelectField,
} from '../Components/ui';
import type { PageProps } from '../types';

type TenderStatus = 'new' | 'favorite' | 'potential' | 'dismissed' | 'archived';

type TenderMatch = {
    id: number;
    tender_id: number;
    search_query_id?: number;
    customer: string | null;
    deadline_reminders_enabled?: boolean;
    action_reminder_enabled?: boolean;
    watch_changes?: boolean;
    title: string;
    description: string | null;
    canonical_url: string;
    reg_number: string | null;
    region: string | null;
    budget_amount: string | null;
    currency: string;
    deadline_at: string | null;
    matched_at: string;
    query_name?: string;
    query_names?: string[];
    source: string;
    status?: TenderStatus;
    tags?: string[];
    next_action_on?: string | null;
    match_reasons: string[];
    participation_exists?: boolean;
    review?: TeamReview;
};

type TeamReviewStatus = 'new' | 'reviewing' | 'qualified' | 'deferred' | 'rejected';
type TeamReview = {
    status: TeamReviewStatus;
    assignee_id: number | null;
    rejection_reason: string | null;
    version: number;
    comments: TeamReviewComment[];
};
type TeamReviewComment = {
    id: number;
    author_id: number | null;
    author_name: string | null;
    body: string;
    created_at: string;
};

type FeedFilters = {
    q: string;
    status: string;
    tag: string;
    query_id: number | null;
    assignee_id?: number | null;
    source: string;
    sort: string;
};

type SavedFeedView = {
    id: number;
    name: string;
    filters: Partial<FeedFilters>;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type TendersPageProps = PageProps<
    Partial<TeamScope> & {
        tenderMatches: {
            data: TenderMatch[];
            current_page: number;
            last_page: number;
            total: number;
            links: PaginationLink[];
        };
        filters: FeedFilters;
        filterOptions: {
            queries: Array<{ id: number; name: string }>;
            tags: string[];
        };
        savedViews: SavedFeedView[];
        sharedMonitorings?: Array<{
            id: number;
            name: string;
            shared_by_id: number | null;
            shared_by_name: string | null;
            can_remove: boolean;
        }>;
        availableMonitorings?: Array<{ id: number; name: string }>;
    }
>;

const statusOptions = [
    { value: 'all', label: 'Все' },
    { value: 'new', label: 'Новые' },
    { value: 'favorite', label: 'Избранные' },
    { value: 'potential', label: 'Потенциальные' },
    { value: 'dismissed', label: 'Скрытые' },
    { value: 'archived', label: 'Убраны' },
];

const teamStatusOptions = [
    { value: 'all', label: 'Все' },
    { value: 'new', label: 'Новые' },
    { value: 'reviewing', label: 'На рассмотрении' },
    { value: 'qualified', label: 'Подходит' },
    { value: 'deferred', label: 'Отложено' },
    { value: 'rejected', label: 'Отклонено' },
];

const sourceOptions = [
    { value: 'all', label: 'Все источники', description: 'Единая лента совпадений' },
    { value: 'rostender', label: 'RosTender', description: 'Подключённые шаблоны' },
];

export default function Tenders() {
    const {
        tenderMatches,
        filters,
        filterOptions,
        savedViews: initialViews,
        team = null,
        members = [],
        can_edit: canEdit = true,
        sharedMonitorings = [],
        availableMonitorings = [],
    } = usePage<TendersPageProps>().props;
    const [search, setSearch] = useState(filters.q);
    const [savedViews, setSavedViews] = useState(initialViews);
    const [viewName, setViewName] = useState('');
    const [savingView, setSavingView] = useState(false);
    const [viewError, setViewError] = useState('');

    const visit = (next: Partial<FeedFilters>): void => {
        const params = { ...filters, q: search, ...next };

        router.get(
            '/tenders',
            { ...cleanParams(params), ...(team ? { team_id: team.id } : {}) },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    const submitSearch = (event: FormEvent): void => {
        event.preventDefault();
        visit({ q: search });
    };

    const saveView = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        if (!viewName.trim()) return;
        setSavingView(true);
        setViewError('');

        try {
            const response = await window.axios.post<{ view: SavedFeedView }>(
                '/tender-feed-views',
                {
                    name: viewName.trim(),
                    filters: cleanParams({ ...filters, q: search }),
                },
            );
            setSavedViews((current) => [response.data.view, ...current]);
            setViewName('');
        } catch {
            setViewError(
                'Не удалось сохранить: проверьте название или лимит представлений.',
            );
        } finally {
            setSavingView(false);
        }
    };

    const applyView = (view: SavedFeedView): void => {
        setSearch(view.filters.q ?? '');
        router.get('/tenders', view.filters, { preserveScroll: true });
    };

    const deleteView = async (view: SavedFeedView): Promise<void> => {
        if (!window.confirm(`Удалить представление «${view.name}»?`)) return;
        await window.axios.delete('/tender-feed-views/' + view.id);
        setSavedViews((current) => current.filter((item) => item.id !== view.id));
    };

    const hasFilters = Boolean(
        filters.q ||
            filters.status !== 'all' ||
            filters.tag ||
            filters.query_id ||
            filters.assignee_id ||
            filters.source !== 'all' ||
            filters.sort !== 'matched_desc',
    );

    const visibleStatusOptions = team ? teamStatusOptions : statusOptions;

    return (
        <>
            <Head title={team ? `Лента · ${team.name}` : 'Мои тендеры'} />
            <AppShell
                activeNav="/tenders"
                className="tenders-page"
                eyebrow={team ? 'Командный поток' : 'Мой поток'}
                title={team ? `Лента · ${team.name}` : 'Тендеры'}
            >
                <TenderWorkNav active="/tenders" />
                <WorkspacePicker path="/tenders" />
                {team ? (
                    <TeamMonitoringPanel
                        teamId={team.id}
                        canEdit={canEdit}
                        shared={sharedMonitorings}
                        available={availableMonitorings}
                    />
                ) : null}
                <GlassCard className="tenders-summary page-enter" tone="quiet">
                    <span className="tenders-summary__mark">
                        <Icon name="layers" size={19} />
                    </span>
                    <div>
                        <p>
                            {team
                                ? 'Общая очередь разбора'
                                : 'Совпадения по мониторингам'}
                        </p>
                        <strong>
                            {tenderMatches.total === 0
                                ? 'Карточек по выбранным условиям нет'
                                : tenderMatches.total +
                                  ' ' +
                                  tenderWord(tenderMatches.total) +
                                  ' в потоке'}
                        </strong>
                    </div>
                    {hasFilters ? <Badge tone="accent">Фильтр</Badge> : null}
                </GlassCard>

                <GlassCard className="tender-feed-controls page-enter page-enter--delay">
                    <div aria-label="Источник тендеров" className="tender-feed-sources">
                        <div>
                            <p>Источник</p>
                            <strong>С чего собрать вашу ленту?</strong>
                        </div>
                        <div className="tender-feed-sources__options">
                            {sourceOptions.map((option) => (
                                <button
                                    aria-pressed={filters.source === option.value}
                                    className={
                                        filters.source === option.value
                                            ? 'is-active'
                                            : ''
                                    }
                                    key={option.value}
                                    onClick={() => visit({ source: option.value })}
                                    type="button"
                                >
                                    <strong>{option.label}</strong>
                                    <span>{option.description}</span>
                                </button>
                            ))}
                        </div>
                    </div>
                    <form className="tender-feed-search" onSubmit={submitSearch}>
                        <SearchInput
                            aria-label="Поиск по ленте"
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Название, заказчик или номер ЕИС"
                            value={search}
                        />
                        <Button size="sm" type="submit">
                            Найти
                        </Button>
                    </form>

                    <div aria-label="Личный статус" className="tender-feed-statuses">
                        {visibleStatusOptions.map((option) => (
                            <FilterChip
                                active={filters.status === option.value}
                                key={option.value}
                                onClick={() => visit({ status: option.value })}
                            >
                                {option.label}
                            </FilterChip>
                        ))}
                    </div>

                    <div className="tender-feed-selects">
                        <SelectField
                            label="Мониторинг"
                            onChange={(event) =>
                                visit({
                                    query_id: event.target.value
                                        ? Number(event.target.value)
                                        : null,
                                })
                            }
                            options={[
                                { value: '', label: 'Все мониторинги' },
                                ...filterOptions.queries.map((query) => ({
                                    value: String(query.id),
                                    label: query.name,
                                })),
                            ]}
                            value={filters.query_id ?? ''}
                        />
                        {team ? (
                            <SelectField
                                label="Ответственный"
                                onChange={(event) =>
                                    visit({
                                        assignee_id: event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    })
                                }
                                options={[
                                    { value: '', label: 'Все сотрудники' },
                                    ...members
                                        .filter((member) => member.role !== 'viewer')
                                        .map((member) => ({
                                            value: String(member.id),
                                            label:
                                                member.name || `Участник ${member.id}`,
                                        })),
                                ]}
                                value={filters.assignee_id ?? ''}
                            />
                        ) : (
                            <SelectField
                                label="Личный тег"
                                onChange={(event) => visit({ tag: event.target.value })}
                                options={[
                                    { value: '', label: 'Все теги' },
                                    ...filterOptions.tags.map((tag) => ({
                                        value: tag,
                                        label: tag,
                                    })),
                                ]}
                                value={filters.tag}
                            />
                        )}
                        <SelectField
                            label="Сортировка"
                            onChange={(event) => visit({ sort: event.target.value })}
                            options={[
                                { value: 'matched_desc', label: 'Сначала новые' },
                                { value: 'deadline_asc', label: 'Ближайший срок' },
                                { value: 'budget_desc', label: 'Сначала дороже' },
                                { value: 'budget_asc', label: 'Сначала дешевле' },
                            ]}
                            value={filters.sort}
                        />
                    </div>

                    {hasFilters ? (
                        <button
                            className="tender-feed-reset"
                            onClick={() => {
                                setSearch('');
                                router.get(
                                    '/tenders',
                                    team ? { team_id: team.id } : {},
                                    { replace: true },
                                );
                            }}
                            type="button"
                        >
                            Сбросить все фильтры
                        </button>
                    ) : null}

                    {!team ? (
                        <div className="tender-feed-views">
                            <div className="tender-feed-views__heading">
                                <div>
                                    <strong>Сохранённые представления</strong>
                                    <small>До 10 наборов фильтров и сортировки</small>
                                </div>
                                <Badge tone="neutral">{savedViews.length}/10</Badge>
                            </div>
                            {savedViews.length > 0 ? (
                                <div className="tender-feed-views__list">
                                    {savedViews.map((view) => (
                                        <span key={view.id}>
                                            <button
                                                onClick={() => applyView(view)}
                                                type="button"
                                            >
                                                {view.name}
                                            </button>
                                            <button
                                                aria-label={`Удалить ${view.name}`}
                                                onClick={() => deleteView(view)}
                                                type="button"
                                            >
                                                ×
                                            </button>
                                        </span>
                                    ))}
                                </div>
                            ) : null}
                            <form
                                className="tender-feed-views__form"
                                onSubmit={saveView}
                            >
                                <label className="form-field">
                                    <span>Название текущего набора</span>
                                    <input
                                        maxLength={60}
                                        onChange={(event) =>
                                            setViewName(event.target.value)
                                        }
                                        placeholder="Например, срочные избранные"
                                        value={viewName}
                                    />
                                </label>
                                <Button
                                    disabled={savingView || savedViews.length >= 10}
                                    size="sm"
                                    type="submit"
                                    variant="secondary"
                                >
                                    {savingView ? 'Сохраняем…' : 'Сохранить вид'}
                                </Button>
                            </form>
                            {viewError ? (
                                <p className="field-error">{viewError}</p>
                            ) : null}
                        </div>
                    ) : null}
                </GlassCard>

                {tenderMatches.data.length > 0 ? (
                    <>
                        <section
                            aria-label="Совпавшие тендеры"
                            className="tenders-preview page-enter page-enter--later"
                        >
                            {tenderMatches.data.map((match) =>
                                team ? (
                                    <TeamFeedTenderCard
                                        key={match.id}
                                        match={match}
                                        teamId={team.id}
                                        members={members}
                                        canEdit={canEdit}
                                    />
                                ) : (
                                    <FeedTenderCard key={match.id} match={match} />
                                ),
                            )}
                        </section>
                        <FeedPagination
                            currentPage={tenderMatches.current_page}
                            lastPage={tenderMatches.last_page}
                            links={tenderMatches.links}
                        />
                    </>
                ) : (
                    <div className="tenders-empty page-enter page-enter--later">
                        <EmptyState
                            action={
                                hasFilters ? (
                                    <button
                                        className="button button--secondary"
                                        onClick={() => {
                                            setSearch('');
                                            router.get(
                                                '/tenders',
                                                team ? { team_id: team.id } : {},
                                                { replace: true },
                                            );
                                        }}
                                        type="button"
                                    >
                                        Сбросить фильтры
                                    </button>
                                ) : (
                                    <Link
                                        className="button button--secondary"
                                        href="/queries"
                                    >
                                        <Icon name="tenders" size={18} />
                                        <span>
                                            {filters.source === 'rostender'
                                                ? 'Подключить шаблон RosTender'
                                                : 'Открыть мониторинги'}
                                        </span>
                                    </Link>
                                )
                            }
                            description={
                                hasFilters
                                    ? 'Попробуйте изменить поиск, статус, тег или мониторинг.'
                                    : filters.source === 'rostender'
                                      ? 'Подключите шаблон RosTender к мониторингу — после первой синхронизации совпадения появятся здесь.'
                                      : 'Когда закупка совпадёт с мониторингом, сервер сохранит её здесь вместе с причиной совпадения.'
                            }
                            icon="compass"
                            title={
                                hasFilters
                                    ? 'По выбранным условиям ничего нет'
                                    : filters.source === 'rostender'
                                      ? 'RosTender пока не подключён к мониторингам'
                                      : 'Пока нет подходящих закупок'
                            }
                        />
                    </div>
                )}

                <section className="empty-hint page-enter page-enter--later">
                    <Icon name="spark" size={17} />
                    <p>
                        {team
                            ? 'Лента содержит только явно подключённые мониторинги. Личные отметки участников остаются приватными.'
                            : 'Фильтры и сортировка записаны в адрес страницы. Карточки принадлежат только вашей ленте и не являются рейтингом.'}
                    </p>
                </section>
            </AppShell>
        </>
    );
}

function TeamMonitoringPanel({
    teamId,
    canEdit,
    shared,
    available,
}: {
    teamId: number;
    canEdit: boolean;
    shared: NonNullable<TendersPageProps['sharedMonitorings']>;
    available: NonNullable<TendersPageProps['availableMonitorings']>;
}) {
    const [selected, setSelected] = useState(available[0]?.id ?? 0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const connect = async (): Promise<void> => {
        if (!selected) return;
        setBusy(true);
        setError('');
        try {
            await window.axios.post(`/teams/${teamId}/monitorings`, {
                search_query_id: selected,
            });
            router.reload();
        } catch {
            setError('Не удалось подключить мониторинг к команде.');
            setBusy(false);
        }
    };

    const disconnect = async (id: number): Promise<void> => {
        if (
            !window.confirm(
                'Отключить мониторинг от командной ленты? Уже созданные заявки сохранятся.',
            )
        )
            return;
        setBusy(true);
        setError('');
        try {
            await window.axios.delete(`/teams/${teamId}/monitorings/${id}`);
            router.reload();
        } catch {
            setError('Не удалось отключить мониторинг.');
            setBusy(false);
        }
    };

    return (
        <GlassCard className="team-feed-monitorings page-enter" tone="quiet">
            <div className="section-heading">
                <div>
                    <p>Источники общей очереди</p>
                    <h2>Подключённые мониторинги</h2>
                </div>
                <Badge tone="neutral">{shared.length}</Badge>
            </div>
            {shared.length ? (
                <div className="team-feed-monitorings__list">
                    {shared.map((query) => (
                        <span key={query.id}>
                            <strong>{query.name}</strong>
                            <small>
                                {query.shared_by_name
                                    ? `Подключил: ${query.shared_by_name}`
                                    : 'Автор удалён'}
                            </small>
                            {canEdit && query.can_remove ? (
                                <button
                                    disabled={busy}
                                    onClick={() => disconnect(query.id)}
                                    type="button"
                                >
                                    Отключить
                                </button>
                            ) : null}
                        </span>
                    ))}
                </div>
            ) : (
                <p className="work-help">
                    Подключите личный мониторинг — его совпадения станут видны
                    участникам команды.
                </p>
            )}
            {canEdit && available.length ? (
                <div className="team-feed-monitorings__connect">
                    <label>
                        Мой мониторинг
                        <select
                            value={selected}
                            onChange={(event) =>
                                setSelected(Number(event.target.value))
                            }
                        >
                            {available.map((query) => (
                                <option key={query.id} value={query.id}>
                                    {query.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        disabled={busy || !selected}
                        onClick={connect}
                        size="sm"
                        variant="secondary"
                    >
                        {busy ? 'Подключаем…' : 'Подключить'}
                    </Button>
                </div>
            ) : null}
            {canEdit && !available.length ? (
                <Link href="/queries">Создать личный мониторинг</Link>
            ) : null}
            {error ? <p className="field-error">{error}</p> : null}
        </GlassCard>
    );
}

function TeamFeedTenderCard({
    match,
    teamId,
    members,
    canEdit,
}: {
    match: TenderMatch;
    teamId: number;
    members: TeamScope['members'];
    canEdit: boolean;
}) {
    const initial = match.review ?? {
        status: 'new' as const,
        assignee_id: null,
        rejection_reason: null,
        version: 0,
        comments: [],
    };
    const [review, setReview] = useState(initial);
    const [status, setStatus] = useState<TeamReviewStatus>(initial.status);
    const [assignee, setAssignee] = useState<number | null>(initial.assignee_id);
    const [reason, setReason] = useState(initial.rejection_reason ?? '');
    const [comments, setComments] = useState(initial.comments);
    const [comment, setComment] = useState('');
    const [saving, setSaving] = useState(false);
    const [participating, setParticipating] = useState(
        Boolean(match.participation_exists),
    );
    const [error, setError] = useState('');

    const save = async (): Promise<void> => {
        setSaving(true);
        setError('');
        try {
            const response = await window.axios.patch<{
                review: Omit<TeamReview, 'comments'>;
            }>(`/teams/${teamId}/tenders/${match.tender_id}/review`, {
                status,
                assignee_id: assignee,
                rejection_reason: status === 'rejected' ? reason : null,
                version: review.version,
            });
            setReview((current) => ({ ...current, ...response.data.review }));
        } catch (requestError: unknown) {
            const code = (requestError as { response?: { status?: number } }).response
                ?.status;
            setError(
                code === 409
                    ? 'Карточка уже изменена коллегой. Обновите страницу.'
                    : 'Не удалось сохранить разбор карточки.',
            );
        } finally {
            setSaving(false);
        }
    };

    const addComment = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        if (!comment.trim()) return;
        setSaving(true);
        setError('');
        try {
            const response = await window.axios.post<{ comment: TeamReviewComment }>(
                `/teams/${teamId}/tenders/${match.tender_id}/comments`,
                { body: comment.trim() },
            );
            setComments((current) => [...current, response.data.comment]);
            setComment('');
            if (review.version === 0)
                setReview((current) => ({ ...current, version: 1 }));
        } catch {
            setError('Не удалось добавить комментарий.');
        } finally {
            setSaving(false);
        }
    };

    const promote = async (): Promise<void> => {
        setSaving(true);
        setError('');
        try {
            await window.axios.post(
                `/teams/${teamId}/tenders/${match.tender_id}/promote`,
                { assignee_id: assignee },
            );
            setParticipating(true);
            setStatus('qualified');
            router.visit(`/tenders/${match.tender_id}/work?team_id=${teamId}`);
        } catch {
            setError('Не удалось передать тендер в участие.');
            setSaving(false);
        }
    };

    return (
        <GlassCard as="article" className="tender-card tender-feed-card team-feed-card">
            <div className="tender-card__meta">
                <Badge tone={teamReviewTone(status)}>{teamReviewLabel(status)}</Badge>
                {match.source === 'rostender' ? (
                    <Badge tone="accent">RosTender</Badge>
                ) : null}
                <span>
                    <Icon name="spark" size={14} /> {match.match_reasons.join(', ')}
                </span>
            </div>
            <h3>{match.title}</h3>
            <p>Мониторинги: {(match.query_names ?? []).join(' · ')}</p>
            {match.description ? (
                <p className="tender-card__description">{match.description}</p>
            ) : null}
            <div className="tender-card__footer">
                <strong>{formatBudget(match.budget_amount, match.currency)}</strong>
                <span>{formatDeadline(match.deadline_at)}</span>
            </div>
            <div className="team-feed-card__review">
                <SelectField
                    label="Решение команды"
                    disabled={!canEdit || saving}
                    value={status}
                    onChange={(event) =>
                        setStatus(event.target.value as TeamReviewStatus)
                    }
                    options={teamStatusOptions.filter(
                        (option) => option.value !== 'all',
                    )}
                />
                <AssigneeSelect
                    label="Ответственный за разбор"
                    disabled={!canEdit || saving}
                    value={assignee}
                    onChange={setAssignee}
                    members={members}
                />
                {status === 'rejected' ? (
                    <label className="form-field">
                        <span>Причина отклонения</span>
                        <textarea
                            disabled={!canEdit || saving}
                            maxLength={2000}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                    </label>
                ) : null}
                {canEdit ? (
                    <Button
                        disabled={saving || (status === 'rejected' && !reason.trim())}
                        onClick={save}
                        size="sm"
                    >
                        {saving ? 'Сохраняем…' : 'Сохранить разбор'}
                    </Button>
                ) : null}
                {review.rejection_reason && status === review.status ? (
                    <p className="work-help">Причина: {review.rejection_reason}</p>
                ) : null}
            </div>
            <div className="team-feed-card__discussion">
                <strong>Обсуждение · {comments.length}</strong>
                {comments.map((item) => (
                    <p key={item.id}>
                        <b>{item.author_name || 'Удалённый участник'}:</b> {item.body}
                    </p>
                ))}
                {canEdit ? (
                    <form onSubmit={addComment}>
                        <input
                            maxLength={4000}
                            placeholder="Комментарий для команды"
                            value={comment}
                            onChange={(event) => setComment(event.target.value)}
                        />
                        <Button
                            disabled={saving || !comment.trim()}
                            size="sm"
                            type="submit"
                            variant="secondary"
                        >
                            Добавить
                        </Button>
                    </form>
                ) : null}
            </div>
            {error ? <p className="field-error">{error}</p> : null}
            <div className="tender-feed-card__links">
                <a href={match.canonical_url} rel="noreferrer" target="_blank">
                    Первоисточник
                </a>
                {participating ? (
                    <Link href={`/tenders/${match.tender_id}/work?team_id=${teamId}`}>
                        Открыть участие
                    </Link>
                ) : canEdit ? (
                    <button disabled={saving} onClick={promote} type="button">
                        Передать в участие
                    </button>
                ) : null}
            </div>
        </GlassCard>
    );
}

function teamReviewLabel(status: TeamReviewStatus): string {
    return {
        new: 'Новый',
        reviewing: 'На рассмотрении',
        qualified: 'Подходит',
        deferred: 'Отложено',
        rejected: 'Отклонено',
    }[status];
}

function teamReviewTone(
    status: TeamReviewStatus,
): 'neutral' | 'accent' | 'success' | 'warning' {
    const tones: Record<
        TeamReviewStatus,
        'neutral' | 'accent' | 'success' | 'warning'
    > = {
        new: 'accent',
        reviewing: 'warning',
        qualified: 'success',
        deferred: 'neutral',
        rejected: 'neutral',
    };

    return tones[status];
}

function FeedTenderCard({ match }: { match: TenderMatch }) {
    const [editing, setEditing] = useState(false);
    const [status, setStatus] = useState<TenderStatus>(match.status ?? 'new');
    const [persistedStatus, setPersistedStatus] = useState<TenderStatus>(
        match.status ?? 'new',
    );
    const [tags, setTags] = useState((match.tags ?? []).join(', '));
    const [nextActionOn, setNextActionOn] = useState(match.next_action_on ?? '');
    const [deadlineReminder, setDeadlineReminder] = useState(
        Boolean(match.deadline_reminders_enabled),
    );
    const [actionReminder, setActionReminder] = useState(
        Boolean(match.action_reminder_enabled),
    );
    const [watchChanges, setWatchChanges] = useState(Boolean(match.watch_changes));
    const [savedFollowUp, setSavedFollowUp] = useState({
        deadline: Boolean(match.deadline_reminders_enabled),
        action: Boolean(match.action_reminder_enabled),
        watch: Boolean(match.watch_changes),
    });
    const [savedTags, setSavedTags] = useState((match.tags ?? []).join(', '));
    const [savedAction, setSavedAction] = useState(match.next_action_on ?? '');
    const cancelEdit = () => {
        setStatus(persistedStatus);
        setTags(savedTags);
        setNextActionOn(savedAction);
        setDeadlineReminder(savedFollowUp.deadline);
        setActionReminder(savedFollowUp.action);
        setWatchChanges(savedFollowUp.watch);
        setEditing(false);
    };
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const save = async (): Promise<void> => {
        if (
            ['dismissed', 'archived'].includes(status) &&
            status !== persistedStatus &&
            !window.confirm(
                status === 'archived'
                    ? 'Убрать карточку из личного списка?'
                    : 'Скрыть карточку из основной ленты?',
            )
        ) {
            return;
        }

        setSaving(true);
        setError('');

        try {
            const response = await window.axios.patch<{
                state: {
                    status: TenderStatus;
                    tags: string[];
                    next_action_on: string | null;
                };
            }>('/tenders/' + match.tender_id + '/state', {
                status,
                tags: splitTags(tags),
                next_action_on: nextActionOn || null,
                deadline_reminders_enabled: deadlineReminder,
                action_reminder_enabled: actionReminder,
                watch_changes: watchChanges,
            });
            setStatus(response.data.state.status);
            setPersistedStatus(response.data.state.status);
            setTags(response.data.state.tags.join(', '));
            setNextActionOn(response.data.state.next_action_on ?? '');
            setSavedFollowUp({
                deadline: deadlineReminder,
                action: actionReminder,
                watch: watchChanges,
            });
            setSavedTags(response.data.state.tags.join(', '));
            setSavedAction(response.data.state.next_action_on ?? '');
            setEditing(false);
        } catch {
            setError('Не удалось сохранить личные поля карточки.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <GlassCard as="article" className="tender-card tender-feed-card">
            <div className="tender-card__meta">
                <Badge tone={statusTone(status)}>{statusLabel(status)}</Badge>
                {match.source === 'rostender' ? (
                    <Badge tone="accent">RosTender</Badge>
                ) : null}
                <span>
                    <Icon name="spark" size={14} /> {match.match_reasons.join(', ')}
                </span>
            </div>
            <h3>{match.title}</h3>
            <p>Мониторинг: {match.query_name}</p>
            {match.description ? (
                <p className="tender-card__description">{match.description}</p>
            ) : null}
            {!editing && tags ? (
                <div className="tender-feed-card__tags">
                    {splitTags(tags).map((tag) => (
                        <span key={tag}>{tag}</span>
                    ))}
                </div>
            ) : null}
            <div className="tender-card__footer">
                <strong>{formatBudget(match.budget_amount, match.currency)}</strong>
                <span>{formatDeadline(match.deadline_at)}</span>
            </div>
            {!editing && nextActionOn ? (
                <p className="tender-feed-card__action">
                    Следующее действие: {formatDate(nextActionOn)}
                </p>
            ) : null}
            {editing ? (
                <div className="tender-feed-card__editor">
                    <SelectField
                        label="Личный статус"
                        onChange={(event) =>
                            setStatus(event.target.value as TenderStatus)
                        }
                        options={statusOptions
                            .filter((option) => option.value !== 'all')
                            .map((option) => ({
                                value: option.value,
                                label: option.label,
                            }))}
                        value={status}
                    />
                    <label className="form-field">
                        <span>Теги через запятую</span>
                        <input
                            maxLength={420}
                            onChange={(event) => setTags(event.target.value)}
                            placeholder="приоритет, позвонить"
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
                    <label className="follow-up-toggle">
                        <input
                            type="checkbox"
                            checked={deadlineReminder}
                            onChange={(e) => setDeadlineReminder(e.target.checked)}
                        />
                        <span>
                            Напомнить в Telegram за 3 дня и за сутки до окончания подачи
                        </span>
                    </label>
                    {!match.deadline_at ? (
                        <p>
                            Источник пока не указал срок. Напоминания начнут работать,
                            когда он появится.
                        </p>
                    ) : null}
                    <label className="follow-up-toggle">
                        <input
                            type="checkbox"
                            checked={actionReminder}
                            onChange={(e) => setActionReminder(e.target.checked)}
                        />
                        <span>
                            Напомнить о следующем действии в 09:00 по часовому поясу
                            профиля
                        </span>
                    </label>
                    <label className="follow-up-toggle">
                        <input
                            type="checkbox"
                            checked={watchChanges}
                            onChange={(e) => setWatchChanges(e.target.checked)}
                        />
                        <span>Сообщать об изменении цены, срока и статуса</span>
                    </label>
                    <p>
                        {match.source === 'rostender'
                            ? 'Выбранные карточки RosTender проверяются по очереди, не чаще раза в 6 часов, в пределах лимита источника.'
                            : 'Изменения ЕИС фиксируются при получении обновлённых данных от источника.'}
                    </p>
                    {error ? <p className="field-error">{error}</p> : null}
                    <div className="tender-feed-card__editor-actions">
                        <Button disabled={saving} onClick={save} size="sm">
                            {saving ? 'Сохраняем…' : 'Сохранить'}
                        </Button>
                        <Button
                            disabled={saving}
                            onClick={cancelEdit}
                            size="sm"
                            variant="secondary"
                        >
                            Отмена
                        </Button>
                    </div>
                </div>
            ) : null}
            {!editing &&
            (savedFollowUp.deadline || savedFollowUp.action || savedFollowUp.watch) ? (
                <p>
                    Включено:{' '}
                    {[
                        savedFollowUp.deadline ? 'напоминания о сроке' : '',
                        savedFollowUp.action ? 'напоминание о действии' : '',
                        savedFollowUp.watch ? 'отслеживание изменений' : '',
                    ]
                        .filter(Boolean)
                        .join(', ')}
                </p>
            ) : null}
            <TenderFeedbackActions
                tenderId={match.tender_id}
                queryId={match.search_query_id ?? 0}
                queryName={match.query_name ?? ''}
                customer={match.customer}
                onDismiss={() => {
                    setStatus('dismissed');
                    setPersistedStatus('dismissed');
                }}
            />
            <div className="tender-feed-card__links">
                <button
                    onClick={() => {
                        if (editing) cancelEdit();
                        else setEditing(true);
                    }}
                    type="button"
                >
                    {editing ? 'Закрыть редактор' : 'Изменить отметку'}
                </button>
                <Link href={'/local/mvp/tenders/' + match.tender_id}>
                    Открыть карточку
                </Link>
                <a href={match.canonical_url} rel="noreferrer" target="_blank">
                    Первоисточник
                </a>
                <Link href={`/tenders/${match.tender_id}/work`}>Участие и задачи</Link>
            </div>
        </GlassCard>
    );
}

function splitTags(value: string): string[] {
    return [
        ...new Set(
            value
                .split(',')
                .map((tag) => tag.trim())
                .filter(Boolean),
        ),
    ].slice(0, 10);
}

function FeedPagination({
    currentPage,
    lastPage,
    links,
}: {
    currentPage: number;
    lastPage: number;
    links: PaginationLink[];
}) {
    if (lastPage <= 1) return null;

    return (
        <nav aria-label="Страницы ленты" className="tender-feed-pagination">
            <span>
                Страница {currentPage} из {lastPage}
            </span>
            <div>
                {links.map((link, index) =>
                    link.url ? (
                        <Link
                            aria-current={link.active ? 'page' : undefined}
                            className={link.active ? 'is-active' : ''}
                            href={link.url}
                            key={link.label + '-' + index}
                            preserveScroll
                        >
                            {paginationLabel(link.label)}
                        </Link>
                    ) : (
                        <span key={link.label + '-' + index}>
                            {paginationLabel(link.label)}
                        </span>
                    ),
                )}
            </div>
        </nav>
    );
}

function cleanParams(filters: FeedFilters): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([key, value]) =>
                value !== '' &&
                value !== null &&
                !(key === 'status' && value === 'all') &&
                !(key === 'sort' && value === 'matched_desc'),
        ),
    ) as Record<string, string | number>;
}

function statusLabel(status: TenderStatus): string {
    return {
        new: 'Новый',
        favorite: 'Избранное',
        potential: 'Потенциальный',
        dismissed: 'Скрытый',
        archived: 'Убран',
    }[status];
}

function statusTone(
    status: TenderStatus,
): 'neutral' | 'accent' | 'success' | 'warning' {
    return {
        new: 'accent',
        favorite: 'success',
        potential: 'warning',
        dismissed: 'neutral',
        archived: 'neutral',
    }[status] as 'neutral' | 'accent' | 'success' | 'warning';
}

function tenderWord(count: number): string {
    const remainder = count % 10;
    const teen = count % 100;

    if (teen >= 11 && teen <= 14) return 'карточек';
    if (remainder === 1) return 'карточка';
    if (remainder >= 2 && remainder <= 4) return 'карточки';
    return 'карточек';
}

function formatBudget(value: string | null, currency: string): string {
    if (value === null) return 'Сумма не указана';

    return (
        new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(
            Number(value),
        ) +
        ' ' +
        (currency === 'RUB' ? '₽' : currency)
    );
}

function formatDeadline(value: string | null): string {
    return value === null ? 'Срок не указан' : 'Срок: ' + formatDate(value);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}

function paginationLabel(label: string): string {
    if (label.includes('Previous')) return 'Назад';
    if (label.includes('Next')) return 'Дальше';
    return label;
}
