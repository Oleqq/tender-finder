import { Head, Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { AppShell } from '../Components/AppShell';
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
    title: string;
    description: string | null;
    canonical_url: string;
    reg_number: string | null;
    region: string | null;
    budget_amount: string | null;
    currency: string;
    deadline_at: string | null;
    matched_at: string;
    query_name: string;
    status: TenderStatus;
    tags: string[];
    next_action_on: string | null;
    match_reasons: string[];
};

type FeedFilters = {
    q: string;
    status: string;
    tag: string;
    query_id: number | null;
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

type TendersPageProps = PageProps<{
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
}>;

const statusOptions = [
    { value: 'all', label: 'Все' },
    { value: 'new', label: 'Новые' },
    { value: 'favorite', label: 'Избранные' },
    { value: 'potential', label: 'Потенциальные' },
    { value: 'dismissed', label: 'Скрытые' },
    { value: 'archived', label: 'Убраны' },
];

export default function Tenders() {
    const {
        tenderMatches,
        filters,
        filterOptions,
        savedViews: initialViews,
    } = usePage<TendersPageProps>().props;
    const [search, setSearch] = useState(filters.q);
    const [savedViews, setSavedViews] = useState(initialViews);
    const [viewName, setViewName] = useState('');
    const [savingView, setSavingView] = useState(false);
    const [viewError, setViewError] = useState('');

    const visit = (next: Partial<FeedFilters>): void => {
        const params = { ...filters, q: search, ...next };

        router.get('/tenders', cleanParams(params), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
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
            filters.sort !== 'matched_desc',
    );

    return (
        <>
            <Head title="Мои тендеры" />
            <AppShell
                activeNav="/tenders"
                className="tenders-page"
                eyebrow="Мой поток"
                title="Тендеры"
            >
                <GlassCard className="tenders-summary page-enter" tone="quiet">
                    <span className="tenders-summary__mark">
                        <Icon name="layers" size={19} />
                    </span>
                    <div>
                        <p>Совпадения по мониторингам</p>
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
                        {statusOptions.map((option) => (
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
                                router.get('/tenders', {}, { replace: true });
                            }}
                            type="button"
                        >
                            Сбросить все фильтры
                        </button>
                    ) : null}

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
                        <form className="tender-feed-views__form" onSubmit={saveView}>
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
                        {viewError ? <p className="field-error">{viewError}</p> : null}
                    </div>
                </GlassCard>

                {tenderMatches.data.length > 0 ? (
                    <>
                        <section
                            aria-label="Совпавшие тендеры"
                            className="tenders-preview page-enter page-enter--later"
                        >
                            {tenderMatches.data.map((match) => (
                                <FeedTenderCard key={match.id} match={match} />
                            ))}
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
                                                {},
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
                                        <span>Открыть мониторинги</span>
                                    </Link>
                                )
                            }
                            description={
                                hasFilters
                                    ? 'Попробуйте изменить поиск, статус, тег или мониторинг.'
                                    : 'Когда закупка совпадёт с мониторингом, сервер сохранит её здесь вместе с причиной совпадения.'
                            }
                            icon="compass"
                            title={
                                hasFilters
                                    ? 'По выбранным условиям ничего нет'
                                    : 'Пока нет подходящих закупок'
                            }
                        />
                    </div>
                )}

                <section className="empty-hint page-enter page-enter--later">
                    <Icon name="spark" size={17} />
                    <p>
                        Фильтры и сортировка записаны в адрес страницы. Карточки
                        принадлежат только вашей ленте и не являются рейтингом.
                    </p>
                </section>
            </AppShell>
        </>
    );
}

function FeedTenderCard({ match }: { match: TenderMatch }) {
    const [editing, setEditing] = useState(false);
    const [status, setStatus] = useState<TenderStatus>(match.status);
    const [persistedStatus, setPersistedStatus] = useState<TenderStatus>(match.status);
    const [tags, setTags] = useState(match.tags.join(', '));
    const [nextActionOn, setNextActionOn] = useState(match.next_action_on ?? '');
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
            });
            setStatus(response.data.state.status);
            setPersistedStatus(response.data.state.status);
            setTags(response.data.state.tags.join(', '));
            setNextActionOn(response.data.state.next_action_on ?? '');
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
                    {error ? <p className="field-error">{error}</p> : null}
                    <div className="tender-feed-card__editor-actions">
                        <Button disabled={saving} onClick={save} size="sm">
                            {saving ? 'Сохраняем…' : 'Сохранить'}
                        </Button>
                        <Button
                            disabled={saving}
                            onClick={() => setEditing(false)}
                            size="sm"
                            variant="secondary"
                        >
                            Отмена
                        </Button>
                    </div>
                </div>
            ) : null}
            <div className="tender-feed-card__links">
                <button onClick={() => setEditing((value) => !value)} type="button">
                    {editing ? 'Закрыть редактор' : 'Изменить отметку'}
                </button>
                <Link href={'/local/mvp/tenders/' + match.tender_id}>
                    Открыть карточку
                </Link>
                <a href={match.canonical_url} rel="noreferrer" target="_blank">
                    Первоисточник
                </a>
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
