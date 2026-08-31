import { Head, Link, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import {
    Badge,
    Button,
    FieldError,
    FilterChip,
    GlassCard,
    InlineAlert,
} from '../Components/ui';
import type { PageProps } from '../types';

type TenderStatus = 'new' | 'favorite' | 'potential' | 'dismissed' | 'archived';
type TenderView = 'inbox' | 'favorite' | 'potential' | 'dismissed' | 'archived';
type TenderCollection = 'current' | 'history';

type SavedSourceFilters = {
    law_44?: boolean;
    law_223?: boolean;
    budget_from?: string | null;
    budget_to?: string | null;
    published_from?: string | null;
    published_to?: string | null;
    pages?: number;
    rss_url?: string | null;
};

type TenderDto = {
    id: number;
    title: string;
    description: string | null;
    region: string | null;
    budget_amount: string | null;
    currency: string;
    published_at: string | null;
    deadline_at: string | null;
    reg_number: string | null;
    customer: string | null;
    category: string | null;
    procurement_law: string | null;
    canonical_url: string;
    status: TenderStatus;
};

type SavedSearchDto = {
    id: number;
    name: string;
    phrase: string;
    keywords: string[];
    filters: { source?: SavedSourceFilters } | null;
    last_run_at: string | null;
    last_run: PreviewResponse['preview'] | null;
};

type MvpWorkspaceProps = {
    currentTenders: TenderDto[];
    currentSearch: SearchContext | null;
    historyTenders: TenderDto[];
    savedSearches: SavedSearchDto[];
};

type PreviewResponse = {
    preview: {
        items_seen: number;
        items_matched: number;
        items_created: number;
        pages_requested: number;
        pages_loaded: number;
        partially_loaded: boolean;
    };
    tenders: TenderDto[];
};

type BulkResponse = { tenders: TenderDto[] };
type SearchResponse = { query: SavedSearchDto };
type SavedSearchRunResponse = PreviewResponse & { query: SavedSearchDto };

type SearchContext = {
    query: string;
    itemsSeen: number;
    itemsMatched: number;
    itemsCreated: number;
    pagesRequested: number;
    pagesLoaded: number;
    partiallyLoaded: boolean;
};

export default function MvpWorkspace() {
    const {
        currentTenders: initialCurrentTenders,
        currentSearch: initialCurrentSearch,
        historyTenders: initialHistoryTenders,
        savedSearches: initialSavedSearches,
    } = usePage<PageProps<MvpWorkspaceProps>>().props;
    const [currentTenders, setCurrentTenders] =
        useState<TenderDto[]>(initialCurrentTenders);
    const [historyTenders, setHistoryTenders] =
        useState<TenderDto[]>(initialHistoryTenders);
    const [savedSearches, setSavedSearches] =
        useState<SavedSearchDto[]>(initialSavedSearches);
    const [searchPhrase, setSearchPhrase] = useState('');
    const [rssUrl, setRssUrl] = useState('');
    const [searchPages, setSearchPages] = useState('3');
    const [searchLaw44, setSearchLaw44] = useState(false);
    const [searchLaw223, setSearchLaw223] = useState(false);
    const [searchBudgetFrom, setSearchBudgetFrom] = useState('');
    const [searchBudgetTo, setSearchBudgetTo] = useState('');
    const [searchPublishedFrom, setSearchPublishedFrom] = useState('');
    const [searchPublishedTo, setSearchPublishedTo] = useState('');
    const [savedSearchName, setSavedSearchName] = useState('');
    const [regionFilter, setRegionFilter] = useState('');
    const [budgetMin, setBudgetMin] = useState('');
    const [budgetMax, setBudgetMax] = useState('');
    const [collection, setCollection] = useState<TenderCollection>('current');
    const [view, setView] = useState<TenderView>('inbox');
    const [searchContext, setSearchContext] = useState<SearchContext | null>(
        initialCurrentSearch,
    );
    const [searchError, setSearchError] = useState('');
    const [actionError, setActionError] = useState('');
    const [searchNotice, setSearchNotice] = useState('');
    const [isSearching, setIsSearching] = useState(false);
    const [isSavingSearch, setIsSavingSearch] = useState(false);
    const [updatingTenderId, setUpdatingTenderId] = useState<number | null>(null);
    const [deletingSearchId, setDeletingSearchId] = useState<number | null>(null);
    const [runningSearchId, setRunningSearchId] = useState<number | null>(null);
    const [selectionMode, setSelectionMode] = useState(false);
    const [selectedTenderIds, setSelectedTenderIds] = useState<number[]>([]);
    const [bulkStatus, setBulkStatus] = useState<TenderStatus | null>(null);

    const collectionTenders =
        collection === 'current' ? currentTenders : historyTenders;
    const hasActiveFilters = Boolean(regionFilter.trim() || budgetMin || budgetMax);
    const hasManualRssUrl = Boolean(rssUrl.trim());

    const visibleTenders = useMemo(() => {
        const min = Number(budgetMin);
        const max = Number(budgetMax);
        const region = regionFilter.trim().toLocaleLowerCase('ru-RU');

        return collectionTenders.filter((tender) => {
            if (
                view === 'inbox' &&
                (tender.status === 'dismissed' || tender.status === 'archived')
            ) {
                return false;
            }

            if (view !== 'inbox' && tender.status !== view) {
                return false;
            }

            if (
                region &&
                !`${tender.region ?? ''} ${tender.title}`
                    .toLocaleLowerCase('ru-RU')
                    .includes(region)
            ) {
                return false;
            }

            const budget = tender.budget_amount ? Number(tender.budget_amount) : null;

            if (!Number.isNaN(min) && budgetMin && (budget === null || budget < min)) {
                return false;
            }

            if (!Number.isNaN(max) && budgetMax && (budget === null || budget > max)) {
                return false;
            }

            return true;
        });
    }, [budgetMax, budgetMin, collectionTenders, regionFilter, view]);

    const viewCounts = useMemo(
        () => ({
            inbox: collectionTenders.filter(
                (tender) =>
                    tender.status !== 'dismissed' && tender.status !== 'archived',
            ).length,
            favorite: collectionTenders.filter((tender) => tender.status === 'favorite')
                .length,
            potential: collectionTenders.filter(
                (tender) => tender.status === 'potential',
            ).length,
            dismissed: collectionTenders.filter(
                (tender) => tender.status === 'dismissed',
            ).length,
            archived: collectionTenders.filter((tender) => tender.status === 'archived')
                .length,
        }),
        [collectionTenders],
    );

    const acceptSearchResult = (
        response: PreviewResponse,
        query: string,
        noticePrefix = '',
    ): void => {
        setCurrentTenders(response.tenders);
        setHistoryTenders((current) => mergeTenders(response.tenders, current));
        setSearchContext({
            query,
            itemsSeen: response.preview.items_seen,
            itemsMatched: response.preview.items_matched,
            itemsCreated: response.preview.items_created,
            pagesRequested: response.preview.pages_requested,
            pagesLoaded: response.preview.pages_loaded,
            partiallyLoaded: response.preview.partially_loaded,
        });
        setCollection('current');
        setView('inbox');
        setSelectedTenderIds([]);
        setSelectionMode(false);
        setSearchNotice(`${noticePrefix}${rssImportNotice(response.preview)}`);
    };

    const importEisRssPreview = async (
        event: FormEvent<HTMLFormElement>,
    ): Promise<void> => {
        event.preventDefault();
        const query = searchPhrase.trim();
        const url = rssUrl.trim();

        if (query.length < 2) {
            setSearchError('Назовите поиск хотя бы двумя символами.');
            return;
        }

        setSearchError('');
        setActionError('');
        setSearchNotice('');
        setIsSearching(true);

        try {
            const response = await window.axios.post<PreviewResponse>(
                '/local/mvp/eis-rss-preview',
                {
                    query,
                    url: url || undefined,
                    pages: Number(searchPages),
                    law_44: !url && searchLaw44 ? true : undefined,
                    law_223: !url && searchLaw223 ? true : undefined,
                    budget_from: !url ? searchBudgetFrom || undefined : undefined,
                    budget_to: !url ? searchBudgetTo || undefined : undefined,
                    published_from: !url ? searchPublishedFrom || undefined : undefined,
                    published_to: !url ? searchPublishedTo || undefined : undefined,
                },
            );
            acceptSearchResult(response.data, query);
        } catch (error) {
            setSearchError(
                requestErrorMessage(
                    error,
                    'ЕИС временно не ответила. Повторите поиск позже.',
                ),
            );
        } finally {
            setIsSearching(false);
        }
    };

    const applyTenderUpdates = (updates: TenderDto[]): void => {
        setCurrentTenders((current) => replaceTenders(current, updates));
        setHistoryTenders((current) => replaceTenders(current, updates));
    };

    const updateTenderStatus = async (
        tender: TenderDto,
        status: TenderStatus,
    ): Promise<void> => {
        setActionError('');
        setUpdatingTenderId(tender.id);

        try {
            const response = await window.axios.post<{ tender: TenderDto }>(
                `/local/mvp/tenders/${tender.id}/status`,
                { status },
            );
            applyTenderUpdates([response.data.tender]);
        } catch {
            setActionError(
                'Не удалось сохранить состояние карточки. Попробуйте ещё раз.',
            );
        } finally {
            setUpdatingTenderId(null);
        }
    };

    const updateSelectedTenders = async (status: TenderStatus): Promise<void> => {
        if (selectedTenderIds.length === 0) {
            return;
        }

        setActionError('');
        setBulkStatus(status);

        try {
            const response = await window.axios.post<BulkResponse>(
                '/local/mvp/tenders/status',
                { tender_ids: selectedTenderIds, status },
            );
            applyTenderUpdates(response.data.tenders);
            setSelectedTenderIds([]);
            setSelectionMode(false);
        } catch {
            setActionError(
                'Не удалось обновить выбранные карточки. Ни один общий тендер не удалён.',
            );
        } finally {
            setBulkStatus(null);
        }
    };

    const toggleSelectionMode = (): void => {
        setSelectionMode((current) => !current);
        setSelectedTenderIds([]);
    };

    const toggleTenderSelection = (tenderId: number): void => {
        setSelectedTenderIds((current) =>
            current.includes(tenderId)
                ? current.filter((id) => id !== tenderId)
                : [...current, tenderId],
        );
    };

    const switchCollection = (nextCollection: TenderCollection): void => {
        setCollection(nextCollection);
        setView('inbox');
        setSelectionMode(false);
        setSelectedTenderIds([]);
    };

    const saveCurrentSearch = async (): Promise<void> => {
        const phrase = searchPhrase.trim();
        const keywords = phrase
            .split(/[\s,]+/)
            .map((keyword) => keyword.trim())
            .filter(Boolean)
            .slice(0, 20);

        if (keywords.length === 0) {
            setActionError('Сначала введите фразу, которую нужно сохранить.');
            return;
        }

        setActionError('');
        setIsSavingSearch(true);

        try {
            const response = await window.axios.post<SearchResponse>('/queries', {
                name: savedSearchName.trim() || phrase,
                keywords,
                minus_keywords: [],
                region: null,
                budget_min: null,
                budget_max: null,
                deadline_from: null,
                deadline_to: null,
                filters: {
                    source: savedSourceFilters({
                        law44: searchLaw44,
                        law223: searchLaw223,
                        budgetFrom: searchBudgetFrom,
                        budgetTo: searchBudgetTo,
                        publishedFrom: searchPublishedFrom,
                        publishedTo: searchPublishedTo,
                        pages: searchPages,
                        rssUrl,
                    }),
                },
            });
            setSavedSearches((current) => [response.data.query, ...current]);
            setSavedSearchName('');
        } catch {
            setActionError(
                'Не удалось сохранить поиск. Тендеры и их статусы не изменены.',
            );
        } finally {
            setIsSavingSearch(false);
        }
    };

    const applySavedSearch = (savedSearch: SavedSearchDto): void => {
        const source = savedSearch.filters?.source;

        setSearchPhrase(savedSearch.phrase);
        setSearchLaw44(Boolean(source?.law_44));
        setSearchLaw223(Boolean(source?.law_223));
        setSearchBudgetFrom(source?.budget_from ?? '');
        setSearchBudgetTo(source?.budget_to ?? '');
        setSearchPublishedFrom(source?.published_from ?? '');
        setSearchPublishedTo(source?.published_to ?? '');
        setSearchPages(String(source?.pages ?? 3));
        setRssUrl(source?.rss_url ?? '');
        setActionError('');
    };

    const runSavedSearch = async (savedSearch: SavedSearchDto): Promise<void> => {
        setActionError('');
        setSearchError('');
        setSearchNotice('');
        setRunningSearchId(savedSearch.id);

        try {
            const response = await window.axios.post<SavedSearchRunResponse>(
                `/queries/${savedSearch.id}/run`,
            );
            setSavedSearches((current) =>
                current.map((item) =>
                    item.id === savedSearch.id ? response.data.query : item,
                ),
            );
            applySavedSearch(response.data.query);
            acceptSearchResult(
                response.data,
                response.data.query.phrase,
                `Запрос «${response.data.query.name}» выполнен. `,
            );
        } catch (error) {
            setActionError(
                requestErrorMessage(
                    error,
                    'Не удалось запустить сохранённый запрос. Попробуйте позже.',
                ),
            );
        } finally {
            setRunningSearchId(null);
        }
    };

    const deleteSavedSearch = async (savedSearch: SavedSearchDto): Promise<void> => {
        setActionError('');
        setDeletingSearchId(savedSearch.id);

        try {
            await window.axios.delete(`/queries/${savedSearch.id}`);
            setSavedSearches((current) =>
                current.filter((item) => item.id !== savedSearch.id),
            );
        } catch {
            setActionError('Не удалось удалить сохранённый поиск. Попробуйте ещё раз.');
        } finally {
            setDeletingSearchId(null);
        }
    };

    const emptyState = getEmptyState({
        collection,
        collectionTenders,
        hasActiveFilters,
        searchContext,
        view,
        archivedCount: viewCounts.archived,
    });

    return (
        <>
            <Head title="Tender Finder — local MVP" />
            <AppShell
                className="mvp-workspace"
                eyebrow="Локальный MVP · ЕИС · super_admin"
                navigationVisible={false}
                role="super_admin"
                title="Tender Finder"
            >
                <GlassCard className="mvp-workspace__search" tone="accent">
                    <div className="section-heading">
                        <div>
                            <p>Шаг 1</p>
                            <p>Источник</p>
                            <h2>ЕИС · государственные закупки</h2>
                        </div>
                        <Badge tone="accent">ЕИС</Badge>
                    </div>
                    <p className="mvp-workspace__lead">
                        Ищем в ЕИС по государственным закупкам. Введите фразу —
                        приложение само запросит RSS расширенного поиска, приведёт
                        карточки к понятному виду и сохранит их без дублей.
                    </p>
                    <form
                        className="mvp-workspace__search-form"
                        onSubmit={importEisRssPreview}
                    >
                        <label className="form-field">
                            <span>Что ищем в ЕИС</span>
                            <input
                                autoFocus
                                maxLength={120}
                                onChange={(event) =>
                                    setSearchPhrase(event.target.value)
                                }
                                placeholder="например, разработка сайта"
                                value={searchPhrase}
                            />
                        </label>
                        <label className="form-field">
                            <span>Глубина поиска</span>
                            <select
                                onChange={(event) => setSearchPages(event.target.value)}
                                value={searchPages}
                            >
                                <option value="1">Первая RSS-страница</option>
                                <option value="3">До трёх RSS-страниц</option>
                                <option value="5">До пяти RSS-страниц</option>
                                <option value="10">
                                    Расширенный поиск: до десяти RSS-страниц
                                </option>
                            </select>
                        </label>
                        <fieldset
                            className="mvp-workspace__source-filters"
                            disabled={hasManualRssUrl}
                        >
                            <legend>Условия поиска в ЕИС</legend>
                            <p>Применяются к RSS-запросу до загрузки карточек.</p>
                            <div className="mvp-workspace__laws">
                                <label>
                                    <input
                                        checked={searchLaw44}
                                        onChange={(event) =>
                                            setSearchLaw44(event.target.checked)
                                        }
                                        type="checkbox"
                                    />
                                    <span>44-ФЗ</span>
                                </label>
                                <label>
                                    <input
                                        checked={searchLaw223}
                                        onChange={(event) =>
                                            setSearchLaw223(event.target.checked)
                                        }
                                        type="checkbox"
                                    />
                                    <span>223-ФЗ</span>
                                </label>
                            </div>
                            <div className="mvp-workspace__filter-grid">
                                <label className="form-field">
                                    <span>НМЦК от, ₽</span>
                                    <input
                                        inputMode="decimal"
                                        min="0"
                                        onChange={(event) =>
                                            setSearchBudgetFrom(event.target.value)
                                        }
                                        placeholder="Без минимума"
                                        type="number"
                                        value={searchBudgetFrom}
                                    />
                                </label>
                                <label className="form-field">
                                    <span>НМЦК до, ₽</span>
                                    <input
                                        inputMode="decimal"
                                        min="0"
                                        onChange={(event) =>
                                            setSearchBudgetTo(event.target.value)
                                        }
                                        placeholder="Без лимита"
                                        type="number"
                                        value={searchBudgetTo}
                                    />
                                </label>
                                <label className="form-field">
                                    <span>Опубликовано с</span>
                                    <input
                                        onChange={(event) =>
                                            setSearchPublishedFrom(event.target.value)
                                        }
                                        type="date"
                                        value={searchPublishedFrom}
                                    />
                                </label>
                                <label className="form-field">
                                    <span>Опубликовано по</span>
                                    <input
                                        min={searchPublishedFrom || undefined}
                                        onChange={(event) =>
                                            setSearchPublishedTo(event.target.value)
                                        }
                                        type="date"
                                        value={searchPublishedTo}
                                    />
                                </label>
                            </div>
                            <small>
                                {hasManualRssUrl
                                    ? 'Ручная RSS-ссылка уже содержит условия ЕИС и заменяет поля выше.'
                                    : 'Регион и ОКПД2 пока задаются только через RSS-ссылку, созданную в ЕИС: для них нужны служебные идентификаторы портала.'}
                            </small>
                        </fieldset>
                        <details className="mvp-workspace__advanced-search">
                            <summary>Расширенные фильтры ЕИС</summary>
                            <p>
                                Необязательно: если настраивали регион, НМЦК или ОКПД2 в
                                ЕИС, вставьте созданную там RSS-ссылку.
                            </p>
                            <label className="form-field">
                                <span>RSS-ссылка из ЕИС</span>
                                <input
                                    inputMode="url"
                                    maxLength={2000}
                                    onChange={(event) => setRssUrl(event.target.value)}
                                    placeholder="https://zakupki.gov.ru/epz/order/extendedsearch/rss..."
                                    type="url"
                                    value={rssUrl}
                                />
                            </label>
                        </details>
                        <Button
                            disabled={isSearching}
                            icon={isSearching ? 'refresh' : 'search'}
                            type="submit"
                        >
                            {isSearching ? 'Ищем в ЕИС…' : 'Найти в ЕИС'}
                        </Button>
                    </form>
                    {searchError ? <FieldError>{searchError}</FieldError> : null}
                    {searchNotice ? (
                        <InlineAlert title="Поиск в ЕИС завершён" tone="success">
                            {searchNotice}
                        </InlineAlert>
                    ) : null}
                </GlassCard>

                <section
                    className="mvp-workspace__results"
                    aria-labelledby="mvp-results"
                >
                    <div className="section-heading">
                        <div>
                            <p>Шаг 2</p>
                            <h2 id="mvp-results">
                                {collection === 'current'
                                    ? 'Текущая выдача'
                                    : 'История карточек'}
                            </h2>
                        </div>
                        <Badge tone="neutral">{visibleTenders.length}</Badge>
                    </div>

                    <div aria-label="Набор тендеров" className="mvp-workspace__views">
                        <FilterChip
                            active={collection === 'current'}
                            onClick={() => switchCollection('current')}
                        >
                            Текущий запрос {currentTenders.length}
                        </FilterChip>
                        <FilterChip
                            active={collection === 'history'}
                            onClick={() => switchCollection('history')}
                        >
                            История {historyTenders.length}
                        </FilterChip>
                    </div>

                    <div
                        aria-label="Состояние тендеров"
                        className="mvp-workspace__views"
                    >
                        <FilterChip
                            active={view === 'inbox'}
                            onClick={() => setView('inbox')}
                        >
                            Все {viewCounts.inbox}
                        </FilterChip>
                        <FilterChip
                            active={view === 'favorite'}
                            onClick={() => setView('favorite')}
                        >
                            Избранное {viewCounts.favorite}
                        </FilterChip>
                        <FilterChip
                            active={view === 'potential'}
                            onClick={() => setView('potential')}
                        >
                            Потенциальные {viewCounts.potential}
                        </FilterChip>
                        <FilterChip
                            active={view === 'dismissed'}
                            onClick={() => setView('dismissed')}
                        >
                            Скрытые {viewCounts.dismissed}
                        </FilterChip>
                        <FilterChip
                            active={view === 'archived'}
                            onClick={() => setView('archived')}
                        >
                            Убраны {viewCounts.archived}
                        </FilterChip>
                    </div>

                    <div className="mvp-workspace__toolbar">
                        <p>
                            {collection === 'current'
                                ? searchContext
                                    ? `Результат поиска ЕИС «${searchContext.query}».`
                                    : 'Здесь появится результат следующего поиска в ЕИС.'
                                : 'Это просмотренные карточки; они не смешаны с новым запросом.'}
                        </p>
                        <Button
                            disabled={collectionTenders.length === 0}
                            onClick={toggleSelectionMode}
                            size="sm"
                            variant={selectionMode ? 'secondary' : 'ghost'}
                        >
                            {selectionMode ? 'Отменить выбор' : 'Выбрать'}
                        </Button>
                    </div>

                    {selectionMode ? (
                        <GlassCard className="mvp-workspace__bulk" tone="quiet">
                            <div>
                                <strong>Выбрано: {selectedTenderIds.length}</strong>
                                <p>
                                    Меняются только ваши статусы. Общие тендеры не
                                    удаляются.
                                </p>
                            </div>
                            <div className="mvp-workspace__bulk-actions">
                                <Button
                                    disabled={
                                        selectedTenderIds.length === 0 ||
                                        bulkStatus !== null
                                    }
                                    onClick={() => updateSelectedTenders('favorite')}
                                    size="sm"
                                    variant="secondary"
                                >
                                    В избранное
                                </Button>
                                <Button
                                    disabled={
                                        selectedTenderIds.length === 0 ||
                                        bulkStatus !== null
                                    }
                                    onClick={() => updateSelectedTenders('potential')}
                                    size="sm"
                                    variant="secondary"
                                >
                                    Потенциальные
                                </Button>
                                <Button
                                    disabled={
                                        selectedTenderIds.length === 0 ||
                                        bulkStatus !== null
                                    }
                                    onClick={() => updateSelectedTenders('dismissed')}
                                    size="sm"
                                    variant="ghost"
                                >
                                    Скрыть
                                </Button>
                                <Button
                                    disabled={
                                        selectedTenderIds.length === 0 ||
                                        bulkStatus !== null
                                    }
                                    onClick={() => updateSelectedTenders('archived')}
                                    size="sm"
                                    variant="ghost"
                                >
                                    {bulkStatus === 'archived'
                                        ? 'Убираем…'
                                        : 'Убрать из моего списка'}
                                </Button>
                            </div>
                        </GlassCard>
                    ) : null}

                    <div className="mvp-workspace__filters">
                        <label className="form-field">
                            <span>Регион или слово в названии</span>
                            <input
                                onChange={(event) =>
                                    setRegionFilter(event.target.value)
                                }
                                placeholder="например, Москва"
                                value={regionFilter}
                            />
                        </label>
                        <div className="mvp-workspace__budget">
                            <label className="form-field">
                                <span>Бюджет от, ₽</span>
                                <input
                                    inputMode="decimal"
                                    min="0"
                                    onChange={(event) =>
                                        setBudgetMin(event.target.value)
                                    }
                                    placeholder="0"
                                    type="number"
                                    value={budgetMin}
                                />
                            </label>
                            <label className="form-field">
                                <span>Бюджет до, ₽</span>
                                <input
                                    inputMode="decimal"
                                    min="0"
                                    onChange={(event) =>
                                        setBudgetMax(event.target.value)
                                    }
                                    placeholder="Без лимита"
                                    type="number"
                                    value={budgetMax}
                                />
                            </label>
                        </div>
                    </div>

                    {actionError ? (
                        <InlineAlert title="Действие не выполнено" tone="warning">
                            {actionError}
                        </InlineAlert>
                    ) : null}

                    <div className="mvp-workspace__tenders">
                        {visibleTenders.length === 0 ? (
                            <GlassCard className="mvp-workspace__empty" tone="quiet">
                                <h3>{emptyState.title}</h3>
                                <p>{emptyState.description}</p>
                            </GlassCard>
                        ) : (
                            visibleTenders.map((tender) => (
                                <TenderWorkspaceCard
                                    isSelected={selectedTenderIds.includes(tender.id)}
                                    key={tender.id}
                                    onSelectionChange={toggleTenderSelection}
                                    onStatusChange={updateTenderStatus}
                                    pending={updatingTenderId === tender.id}
                                    selecting={selectionMode}
                                    tender={tender}
                                />
                            ))
                        )}
                    </div>
                </section>

                <GlassCard className="mvp-workspace__saved" tone="quiet">
                    <div className="section-heading">
                        <div>
                            <p>Необязательно</p>
                            <h2>Сохранённые поиски</h2>
                        </div>
                        <Badge tone="neutral">{savedSearches.length}</Badge>
                    </div>
                    <p>
                        Сохраните фразу и все условия ЕИС. Запрос можно повторно
                        запустить одной кнопкой; расписание и уведомления при этом не
                        включаются.
                    </p>
                    <label className="form-field">
                        <span>Название сохранённого запроса</span>
                        <input
                            maxLength={120}
                            onChange={(event) => setSavedSearchName(event.target.value)}
                            placeholder={
                                searchPhrase.trim() || 'Например, Поддержка сайтов'
                            }
                            value={savedSearchName}
                        />
                    </label>
                    <Button
                        disabled={isSavingSearch || searchPhrase.trim().length < 2}
                        icon="plus"
                        onClick={saveCurrentSearch}
                        variant="secondary"
                    >
                        {isSavingSearch ? 'Сохраняем…' : 'Сохранить запрос'}
                    </Button>
                    {savedSearches.length > 0 ? (
                        <div className="mvp-workspace__saved-list">
                            {savedSearches.map((savedSearch) => (
                                <div
                                    className="mvp-workspace__saved-row"
                                    key={savedSearch.id}
                                >
                                    <div>
                                        <strong>{savedSearch.name}</strong>
                                        <span>{savedSearch.phrase}</span>
                                        <span>
                                            {savedSearchFilterLabel(
                                                savedSearch.filters?.source,
                                            )}
                                        </span>
                                        <span>{savedSearchRunLabel(savedSearch)}</span>
                                    </div>
                                    <div className="mvp-workspace__saved-actions">
                                        <Button
                                            disabled={runningSearchId !== null}
                                            onClick={() => runSavedSearch(savedSearch)}
                                            size="sm"
                                        >
                                            {runningSearchId === savedSearch.id
                                                ? 'Запускаем…'
                                                : 'Запустить сейчас'}
                                        </Button>
                                        <Button
                                            onClick={() =>
                                                applySavedSearch(savedSearch)
                                            }
                                            size="sm"
                                            variant="secondary"
                                        >
                                            Изменить условия
                                        </Button>
                                        <Button
                                            disabled={
                                                deletingSearchId === savedSearch.id
                                            }
                                            onClick={() =>
                                                deleteSavedSearch(savedSearch)
                                            }
                                            size="sm"
                                            variant="ghost"
                                        >
                                            {deletingSearchId === savedSearch.id
                                                ? 'Удаляем…'
                                                : 'Удалить'}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </GlassCard>

                <p className="mvp-workspace__source-note">
                    Источник — RSS расширенного поиска ЕИС. По фразе ссылка создаётся
                    автоматически; ручная RSS-ссылка нужна только для расширенных
                    фильтров. Автоматический мониторинг и Telegram-уведомления пока не
                    включены.
                </p>
            </AppShell>
        </>
    );
}

function TenderWorkspaceCard({
    tender,
    pending,
    onStatusChange,
    selecting,
    isSelected,
    onSelectionChange,
}: {
    tender: TenderDto;
    pending: boolean;
    onStatusChange: (tender: TenderDto, status: TenderStatus) => Promise<void>;
    selecting: boolean;
    isSelected: boolean;
    onSelectionChange: (tenderId: number) => void;
}) {
    const cardClassName = [
        'mvp-tender-card',
        selecting ? 'is-selecting' : '',
        isSelected ? 'is-selected' : '',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <GlassCard as="article" className={cardClassName}>
            <div className="mvp-tender-card__topline">
                <Badge tone={statusTone(tender.status)}>
                    {statusLabel(tender.status)}
                </Badge>
                {tender.deadline_at ? (
                    <span>До {formatDate(tender.deadline_at)}</span>
                ) : null}
            </div>
            <h3>
                <Link href={`/local/mvp/tenders/${tender.id}`}>{tender.title}</Link>
            </h3>
            {(tender.category || tender.procurement_law) && (
                <div className="mvp-tender-card__facts" aria-label="Тип закупки">
                    {tender.category ? <span>{tender.category}</span> : null}
                    {tender.procurement_law ? (
                        <span>{tender.procurement_law}-ФЗ</span>
                    ) : null}
                </div>
            )}
            {tender.customer ? (
                <p className="mvp-tender-card__customer">
                    <span>Заказчик:</span> {tender.customer}
                </p>
            ) : null}
            {tender.region ? (
                <p className="mvp-tender-card__region">{tender.region}</p>
            ) : null}
            {tender.description ? (
                <p className="mvp-tender-card__description">{tender.description}</p>
            ) : null}
            <div className="mvp-tender-card__meta">
                <strong>{formatBudget(tender.budget_amount, tender.currency)}</strong>
                <div>
                    {tender.published_at ? (
                        <span>Опубликован {formatDate(tender.published_at)}</span>
                    ) : null}
                    {tender.reg_number ? <span>ЕИС № {tender.reg_number}</span> : null}
                </div>
            </div>
            {selecting ? (
                <label className="mvp-tender-card__select">
                    <input
                        checked={isSelected}
                        onChange={() => onSelectionChange(tender.id)}
                        type="checkbox"
                    />
                    <span>{isSelected ? 'Выбрано' : 'Выбрать карточку'}</span>
                </label>
            ) : (
                <div className="mvp-tender-card__actions">
                    {tender.status === 'favorite' ? (
                        <Button
                            disabled={pending}
                            onClick={() => onStatusChange(tender, 'new')}
                            size="sm"
                            variant="secondary"
                        >
                            Убрать из избранного
                        </Button>
                    ) : (
                        <Button
                            disabled={pending || tender.status === 'archived'}
                            onClick={() => onStatusChange(tender, 'favorite')}
                            size="sm"
                            variant="secondary"
                        >
                            В избранное
                        </Button>
                    )}
                    {tender.status === 'potential' ? (
                        <Button
                            disabled={pending}
                            onClick={() => onStatusChange(tender, 'new')}
                            size="sm"
                            variant="ghost"
                        >
                            Снять отметку
                        </Button>
                    ) : (
                        <Button
                            disabled={pending || tender.status === 'archived'}
                            onClick={() => onStatusChange(tender, 'potential')}
                            size="sm"
                            variant="ghost"
                        >
                            Потенциальный
                        </Button>
                    )}
                    {tender.status === 'dismissed' ? (
                        <Button
                            disabled={pending}
                            onClick={() => onStatusChange(tender, 'new')}
                            size="sm"
                            variant="ghost"
                        >
                            Вернуть
                        </Button>
                    ) : (
                        <Button
                            disabled={pending || tender.status === 'archived'}
                            onClick={() => onStatusChange(tender, 'dismissed')}
                            size="sm"
                            variant="ghost"
                        >
                            Скрыть
                        </Button>
                    )}
                    <Button
                        disabled={pending}
                        onClick={() =>
                            onStatusChange(
                                tender,
                                tender.status === 'archived' ? 'new' : 'archived',
                            )
                        }
                        size="sm"
                        variant="ghost"
                    >
                        {tender.status === 'archived'
                            ? 'Вернуть в список'
                            : 'Убрать из моего списка'}
                    </Button>
                    <Link
                        className="mvp-tender-card__details"
                        href={`/local/mvp/tenders/${tender.id}`}
                    >
                        Подробнее
                    </Link>
                    <a href={tender.canonical_url} rel="noreferrer" target="_blank">
                        Источник
                    </a>
                </div>
            )}
        </GlassCard>
    );
}

function mergeTenders(incoming: TenderDto[], current: TenderDto[]): TenderDto[] {
    const incomingIds = new Set(incoming.map((tender) => tender.id));

    return [...incoming, ...current.filter((tender) => !incomingIds.has(tender.id))];
}

function savedSourceFilters({
    law44,
    law223,
    budgetFrom,
    budgetTo,
    publishedFrom,
    publishedTo,
    pages,
    rssUrl,
}: {
    law44: boolean;
    law223: boolean;
    budgetFrom: string;
    budgetTo: string;
    publishedFrom: string;
    publishedTo: string;
    pages: string;
    rssUrl: string;
}): SavedSourceFilters {
    const manualRssUrl = rssUrl.trim();

    return {
        law_44: manualRssUrl === '' ? law44 : false,
        law_223: manualRssUrl === '' ? law223 : false,
        budget_from: manualRssUrl === '' ? budgetFrom || null : null,
        budget_to: manualRssUrl === '' ? budgetTo || null : null,
        published_from: manualRssUrl === '' ? publishedFrom || null : null,
        published_to: manualRssUrl === '' ? publishedTo || null : null,
        pages: Number(pages) || 3,
        rss_url: manualRssUrl || null,
    };
}

function savedSearchFilterLabel(source?: SavedSourceFilters): string {
    if (!source) {
        return 'Без сохранённых условий ЕИС';
    }

    if (source.rss_url) {
        return `Ручная RSS-ссылка ЕИС · до ${source.pages ?? 3} стр.`;
    }

    const parts: string[] = [];

    if (source.law_44) {
        parts.push('44-ФЗ');
    }

    if (source.law_223) {
        parts.push('223-ФЗ');
    }

    if (source.budget_from) {
        parts.push(`НМЦК от ${source.budget_from} ₽`);
    }

    if (source.budget_to) {
        parts.push(`НМЦК до ${source.budget_to} ₽`);
    }

    if (source.published_from || source.published_to) {
        parts.push(
            source.published_from && source.published_to
                ? `Опубликовано ${source.published_from} — ${source.published_to}`
                : `Опубликовано ${source.published_from ?? `до ${source.published_to}`}`,
        );
    }

    parts.push(`до ${source.pages ?? 3} RSS-стр.`);

    return parts.join(' · ');
}

function savedSearchRunLabel(savedSearch: SavedSearchDto): string {
    if (!savedSearch.last_run_at || !savedSearch.last_run) {
        return 'Ещё не запускался';
    }

    const date = new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(savedSearch.last_run_at));

    return `Последний запуск: ${date} · найдено ${savedSearch.last_run.items_matched} · новых ${savedSearch.last_run.items_created}`;
}

function replaceTenders(current: TenderDto[], updates: TenderDto[]): TenderDto[] {
    const updatesById = new Map(updates.map((tender) => [tender.id, tender]));

    return current.map((tender) => updatesById.get(tender.id) ?? tender);
}

function getEmptyState({
    collection,
    collectionTenders,
    hasActiveFilters,
    searchContext,
    view,
    archivedCount,
}: {
    collection: TenderCollection;
    collectionTenders: TenderDto[];
    hasActiveFilters: boolean;
    searchContext: SearchContext | null;
    view: TenderView;
    archivedCount: number;
}): { title: string; description: string } {
    if (collection === 'current' && searchContext && collectionTenders.length === 0) {
        return {
            title: `По запросу «${searchContext.query}» ничего не найдено`,
            description:
                searchContext.itemsSeen === 0
                    ? `На ${searchContext.pagesLoaded} RSS-страницах ЕИС пока нет карточек. Измените фильтры в расширенном поиске ЕИС или откройте историю прошлых проверок.`
                    : searchContext.itemsMatched === 0
                      ? `ЕИС вернула ${searchContext.itemsSeen}, но ни одна карточка не содержит все слова запроса в предмете закупки.`
                      : `ЕИС отобрала ${searchContext.itemsMatched}, но карточки не удалось показать. Повторите загрузку позже.`,
        };
    }

    if (collection === 'current' && !searchContext) {
        return {
            title: 'Текущей выдачи пока нет',
            description:
                'Введите фразу и нажмите «Найти в ЕИС». Старые карточки не подмешиваются в этот результат.',
        };
    }

    if (collection === 'history' && collectionTenders.length === 0) {
        return {
            title: 'История пока пуста',
            description: 'После первого поиска здесь появятся просмотренные карточки.',
        };
    }

    if (hasActiveFilters) {
        return {
            title: 'Фильтры скрыли все карточки',
            description:
                'Смените регион или границы бюджета, чтобы снова увидеть результаты.',
        };
    }

    if (view === 'inbox' && archivedCount > 0) {
        return {
            title: 'В разделе «Все» скрыты убранные карточки',
            description: `Откройте «Убраны ${archivedCount}», чтобы посмотреть или вернуть их в список.`,
        };
    }

    return {
        title: `В разделе «${viewLabel(view)}» пока нет карточек`,
        description: 'Выберите другой статус или измените отметку у нужного тендера.',
    };
}

function rssImportNotice(preview: PreviewResponse['preview']): string {
    if (preview.items_seen === 0) {
        return 'ЕИС не вернула карточек по этой фразе. Попробуйте уточнить запрос или изменить его формулировку.';
    }

    const pages = `Проверено RSS-страниц: ${preview.pages_loaded} из ${preview.pages_requested}.`;
    const partial = preview.partially_loaded
        ? ' Часть следующих страниц ЕИС не ответила; уже полученные карточки показаны.'
        : '';

    return `${pages} ЕИС вернула: ${preview.items_seen}. По предмету закупки подходят: ${preview.items_matched}. Новых карточек в локальной базе: ${preview.items_created}.${partial}`;
}

function requestErrorMessage(error: unknown, fallback: string): string {
    const response = (
        error as {
            response?: {
                data?: {
                    errors?: Record<string, string[]>;
                    message?: string;
                };
            };
        }
    ).response;
    const fieldMessage =
        response?.data?.errors?.query?.[0] ??
        response?.data?.errors?.url?.[0] ??
        response?.data?.errors?.pages?.[0] ??
        response?.data?.errors?.budget_from?.[0] ??
        response?.data?.errors?.budget_to?.[0] ??
        response?.data?.errors?.published_from?.[0] ??
        response?.data?.errors?.published_to?.[0];

    if (typeof fieldMessage === 'string' && fieldMessage.trim() !== '') {
        return fieldMessage;
    }

    const message = response?.data?.message;

    return typeof message === 'string' && message.trim() !== '' ? message : fallback;
}

function formatBudget(amount: string | null, currency: string): string {
    if (!amount) {
        return 'Бюджет не указан';
    }

    return `${new Intl.NumberFormat('ru-RU', {
        maximumFractionDigits: 0,
    }).format(Number(amount))} ${currency === 'RUB' ? '₽' : currency}`;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function statusLabel(status: TenderStatus): string {
    return {
        new: 'Новый',
        favorite: 'Избранное',
        potential: 'Потенциальный',
        dismissed: 'Скрыт',
        archived: 'Убран из списка',
    }[status];
}

function viewLabel(view: TenderView): string {
    return {
        inbox: 'Все',
        favorite: 'Избранное',
        potential: 'Потенциальные',
        dismissed: 'Скрытые',
        archived: 'Убраны из списка',
    }[view];
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
