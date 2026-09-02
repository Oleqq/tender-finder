import { Head, Link, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import {
    EisCatalogFilters,
    type EisOkpd2Option,
    type EisRegionOption,
} from '../Components/EisCatalogFilters';
import {
    SavedSearchRunHistory,
    type SavedSearchRunResult,
} from '../Components/SavedSearchRunHistory';
import { TenderComparison } from '../Components/TenderComparison';
import {
    Badge,
    Button,
    FieldError,
    FilterChip,
    GlassCard,
    InlineAlert,
    SelectField,
} from '../Components/ui';
import { downloadTenderExport, type TenderExportFormat } from '../lib/tenderExport';
import type { PageProps } from '../types';

type TenderStatus = 'new' | 'favorite' | 'potential' | 'dismissed' | 'archived';
type TenderView = 'inbox' | 'favorite' | 'potential' | 'dismissed' | 'archived';
type TenderCollection = 'current' | 'history';
type TenderSort =
    | 'published_desc'
    | 'deadline_asc'
    | 'budget_desc'
    | 'budget_asc'
    | 'favorite_first'
    | 'new_first';
type SearchMatchMode = 'all' | 'any' | 'exact';

type SavedRelevanceFilters = {
    match_mode?: SearchMatchMode;
};

type TenderMatchReason = {
    mode: SearchMatchMode;
    matched_terms: string[];
    minus_keywords_checked: string[];
};

type SavedSourceFilters = {
    law_44?: boolean;
    law_223?: boolean;
    stage_application?: boolean;
    stage_commission?: boolean;
    stage_completed?: boolean;
    stage_cancelled?: boolean;
    joint_purchase?: boolean;
    placed_by_separate_subdivision?: boolean;
    union_state_budget?: boolean;
    created_by_customer_representative?: boolean;
    smp_sono?: boolean;
    budget_from?: string | null;
    budget_to?: string | null;
    published_from?: string | null;
    published_to?: string | null;
    regions?: EisRegionOption[];
    okpd2?: EisOkpd2Option[];
    okpd2_with_nested?: boolean;
    pages?: number;
    rss_url?: string | null;
};

type SearchStages = {
    application: boolean;
    commission: boolean;
    completed: boolean;
    cancelled: boolean;
};

type SearchAdditionalInfo = {
    jointPurchase: boolean;
    placedBySeparateSubdivision: boolean;
    unionStateBudget: boolean;
    createdByCustomerRepresentative: boolean;
    smpSono: boolean;
};

const defaultSearchStages = (): SearchStages => ({
    application: true,
    commission: true,
    completed: true,
    cancelled: true,
});

const defaultAdditionalInfo = (): SearchAdditionalInfo => ({
    jointPurchase: false,
    placedBySeparateSubdivision: false,
    unionStateBudget: false,
    createdByCustomerRepresentative: false,
    smpSono: false,
});

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
    delivery_place: string | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    application_security: string | null;
    contract_security: string | null;
    enriched_at: string | null;
    canonical_url: string;
    status: TenderStatus;
    note: string | null;
    tags: string[];
    next_action_on: string | null;
    match_reason: TenderMatchReason | null;
};

type SavedSearchDto = {
    id: number;
    name: string;
    phrase: string;
    keywords: string[];
    minus_keywords: string[] | null;
    filters: {
        source?: SavedSourceFilters;
        relevance?: SavedRelevanceFilters;
    } | null;
    last_run_at: string | null;
    last_run: PreviewResponse['preview'] | null;
};

type MvpWorkspaceProps = {
    currentTenders: TenderDto[];
    currentSearch: SearchContext | null;
    historyTenders: TenderDto[];
    savedSearches: SavedSearchDto[];
    eisRegions: EisRegionOption[];
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
    matchMode: SearchMatchMode;
    minusKeywords: string[];
};

export default function MvpWorkspace() {
    const {
        currentTenders: initialCurrentTenders,
        currentSearch: initialCurrentSearch,
        historyTenders: initialHistoryTenders,
        savedSearches: initialSavedSearches,
        eisRegions,
    } = usePage<PageProps<MvpWorkspaceProps>>().props;
    const [currentTenders, setCurrentTenders] =
        useState<TenderDto[]>(initialCurrentTenders);
    const [historyTenders, setHistoryTenders] =
        useState<TenderDto[]>(initialHistoryTenders);
    const [savedSearches, setSavedSearches] =
        useState<SavedSearchDto[]>(initialSavedSearches);
    const [searchPhrase, setSearchPhrase] = useState('');
    const [searchMatchMode, setSearchMatchMode] = useState<SearchMatchMode>('all');
    const [searchMinusKeywords, setSearchMinusKeywords] = useState('');
    const [rssUrl, setRssUrl] = useState('');
    const [searchPages, setSearchPages] = useState('3');
    const [searchLaw44, setSearchLaw44] = useState(false);
    const [searchLaw223, setSearchLaw223] = useState(false);
    const [searchStages, setSearchStages] = useState<SearchStages>(defaultSearchStages);
    const [searchAdditionalInfo, setSearchAdditionalInfo] =
        useState<SearchAdditionalInfo>(defaultAdditionalInfo);
    const [searchBudgetFrom, setSearchBudgetFrom] = useState('');
    const [searchBudgetTo, setSearchBudgetTo] = useState('');
    const [searchPublishedFrom, setSearchPublishedFrom] = useState('');
    const [searchPublishedTo, setSearchPublishedTo] = useState('');
    const [searchRegions, setSearchRegions] = useState<EisRegionOption[]>([]);
    const [searchOkpd2, setSearchOkpd2] = useState<EisOkpd2Option[]>([]);
    const [searchOkpd2WithNested, setSearchOkpd2WithNested] = useState(true);
    const [savedSearchName, setSavedSearchName] = useState('');
    const [regionFilter, setRegionFilter] = useState('');
    const [budgetMin, setBudgetMin] = useState('');
    const [budgetMax, setBudgetMax] = useState('');
    const [tagFilter, setTagFilter] = useState('');
    const [tenderSort, setTenderSort] = useState<TenderSort>(readTenderSort);
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
    const [comparisonOpen, setComparisonOpen] = useState(false);
    const [historySearchId, setHistorySearchId] = useState<number | null>(null);
    const [exportingFormat, setExportingFormat] = useState<TenderExportFormat | null>(
        null,
    );

    useEffect(() => {
        window.localStorage.setItem('tender-finder:workspace-sort', tenderSort);
    }, [tenderSort]);

    const collectionTenders =
        collection === 'current' ? currentTenders : historyTenders;
    const hasActiveFilters = Boolean(
        regionFilter.trim() || budgetMin || budgetMax || tagFilter.trim(),
    );
    const hasManualRssUrl = Boolean(rssUrl.trim());
    const comparisonTenders = collectionTenders.filter((tender) =>
        selectedTenderIds.includes(tender.id),
    );

    const visibleTenders = useMemo(() => {
        const min = Number(budgetMin);
        const max = Number(budgetMax);
        const region = regionFilter.trim().toLocaleLowerCase('ru-RU');
        const tag = tagFilter.trim().toLocaleLowerCase('ru-RU');

        const filtered = collectionTenders.filter((tender) => {
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

            if (
                tag &&
                !tender.tags.some((item) =>
                    item.toLocaleLowerCase('ru-RU').includes(tag),
                )
            ) {
                return false;
            }

            return true;
        });

        return filtered
            .map((tender, index) => ({ tender, index }))
            .sort((left, right) => {
                const compared = compareTenders(left.tender, right.tender, tenderSort);

                return compared === 0 ? left.index - right.index : compared;
            })
            .map(({ tender }) => tender);
    }, [
        budgetMax,
        budgetMin,
        collectionTenders,
        regionFilter,
        tagFilter,
        tenderSort,
        view,
    ]);

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
        relevance: { matchMode: SearchMatchMode; minusKeywords: string[] },
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
            matchMode: relevance.matchMode,
            minusKeywords: relevance.minusKeywords,
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
        const minusKeywords = parseMinusKeywords(searchMinusKeywords);

        if (query.length < 2) {
            setSearchError('Назовите поиск хотя бы двумя символами.');
            return;
        }

        if (!url && !Object.values(searchStages).some(Boolean)) {
            setSearchError('Выберите хотя бы один этап закупки.');
            return;
        }

        if (
            minusKeywords.length > 20 ||
            minusKeywords.some((keyword) => keyword.length > 100)
        ) {
            setSearchError('Укажите не более 20 исключений, каждое — до 100 символов.');
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
                    match_mode: searchMatchMode,
                    minus_keywords: minusKeywords,
                    url: url || undefined,
                    pages: Number(searchPages),
                    law_44: !url && searchLaw44 ? true : undefined,
                    law_223: !url && searchLaw223 ? true : undefined,
                    stage_application: !url ? searchStages.application : undefined,
                    stage_commission: !url ? searchStages.commission : undefined,
                    stage_completed: !url ? searchStages.completed : undefined,
                    stage_cancelled: !url ? searchStages.cancelled : undefined,
                    joint_purchase:
                        !url && searchAdditionalInfo.jointPurchase ? true : undefined,
                    placed_by_separate_subdivision:
                        !url && searchAdditionalInfo.placedBySeparateSubdivision
                            ? true
                            : undefined,
                    union_state_budget:
                        !url && searchAdditionalInfo.unionStateBudget
                            ? true
                            : undefined,
                    created_by_customer_representative:
                        !url && searchAdditionalInfo.createdByCustomerRepresentative
                            ? true
                            : undefined,
                    smp_sono: !url && searchAdditionalInfo.smpSono ? true : undefined,
                    budget_from: !url ? searchBudgetFrom || undefined : undefined,
                    budget_to: !url ? searchBudgetTo || undefined : undefined,
                    published_from: !url ? searchPublishedFrom || undefined : undefined,
                    published_to: !url ? searchPublishedTo || undefined : undefined,
                    regions:
                        !url && searchRegions.length > 0 ? searchRegions : undefined,
                    okpd2: !url && searchOkpd2.length > 0 ? searchOkpd2 : undefined,
                    okpd2_with_nested:
                        !url && searchOkpd2.length > 0
                            ? searchOkpd2WithNested
                            : undefined,
                },
            );
            acceptSearchResult(response.data, query, {
                matchMode: searchMatchMode,
                minusKeywords,
            });
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

    const exportTenders = async (format: TenderExportFormat): Promise<void> => {
        const selected = selectionMode && selectedTenderIds.length > 0;
        const tenderIds = selected
            ? selectedTenderIds
            : visibleTenders.map((tender) => tender.id);

        if (tenderIds.length === 0) {
            setActionError('Нет карточек для экспорта.');
            return;
        }

        setExportingFormat(format);
        setActionError('');

        try {
            await downloadTenderExport({
                format,
                scope: selected ? 'selected' : 'current',
                tender_ids: tenderIds,
                filter_summary: exportFilterSummary({
                    collection,
                    searchContext,
                    view,
                    regionFilter,
                    budgetMin,
                    budgetMax,
                    tagFilter,
                    selectedCount: selected ? tenderIds.length : 0,
                }),
            });
        } catch {
            setActionError('Не удалось подготовить файл экспорта.');
        } finally {
            setExportingFormat(null);
        }
    };

    const saveCurrentSearch = async (): Promise<void> => {
        const phrase = searchPhrase.trim();
        const keywords = phrase
            .split(/[\s,]+/)
            .map((keyword) => keyword.trim())
            .filter(Boolean)
            .slice(0, 20);
        const minusKeywords = parseMinusKeywords(searchMinusKeywords);

        if (keywords.length === 0) {
            setActionError('Сначала введите фразу, которую нужно сохранить.');
            return;
        }

        if (
            minusKeywords.length > 20 ||
            minusKeywords.some((keyword) => keyword.length > 100)
        ) {
            setActionError('Укажите не более 20 исключений, каждое — до 100 символов.');
            return;
        }

        setActionError('');
        setIsSavingSearch(true);

        try {
            const response = await window.axios.post<SearchResponse>('/queries', {
                name: savedSearchName.trim() || phrase,
                keywords,
                minus_keywords: minusKeywords,
                region: null,
                budget_min: null,
                budget_max: null,
                deadline_from: null,
                deadline_to: null,
                filters: {
                    relevance: { match_mode: searchMatchMode },
                    source: savedSourceFilters({
                        law44: searchLaw44,
                        law223: searchLaw223,
                        stages: searchStages,
                        additionalInfo: searchAdditionalInfo,
                        budgetFrom: searchBudgetFrom,
                        budgetTo: searchBudgetTo,
                        publishedFrom: searchPublishedFrom,
                        publishedTo: searchPublishedTo,
                        regions: searchRegions,
                        okpd2: searchOkpd2,
                        okpd2WithNested: searchOkpd2WithNested,
                        pages: searchPages,
                        rssUrl,
                    }),
                },
            });
            setSavedSearches((current) => [response.data.query, ...current]);
            setSavedSearchName('');
        } catch (error) {
            setActionError(
                requestErrorMessage(
                    error,
                    'Не удалось сохранить поиск. Тендеры и их статусы не изменены.',
                ),
            );
        } finally {
            setIsSavingSearch(false);
        }
    };

    const applySavedSearch = (savedSearch: SavedSearchDto): void => {
        const source = savedSearch.filters?.source;

        setSearchPhrase(savedSearch.phrase);
        setSearchMatchMode(savedSearchMatchMode(savedSearch.filters?.relevance));
        setSearchMinusKeywords((savedSearch.minus_keywords ?? []).join(', '));
        setSearchLaw44(Boolean(source?.law_44));
        setSearchLaw223(Boolean(source?.law_223));
        setSearchStages(savedSearchStages(source));
        setSearchAdditionalInfo({
            jointPurchase: Boolean(source?.joint_purchase),
            placedBySeparateSubdivision: Boolean(
                source?.placed_by_separate_subdivision,
            ),
            unionStateBudget: Boolean(source?.union_state_budget),
            createdByCustomerRepresentative: Boolean(
                source?.created_by_customer_representative,
            ),
            smpSono: Boolean(source?.smp_sono),
        });
        setSearchBudgetFrom(source?.budget_from ?? '');
        setSearchBudgetTo(source?.budget_to ?? '');
        setSearchPublishedFrom(source?.published_from ?? '');
        setSearchPublishedTo(source?.published_to ?? '');
        setSearchRegions(source?.regions ?? []);
        setSearchOkpd2(source?.okpd2 ?? []);
        setSearchOkpd2WithNested(source?.okpd2_with_nested ?? true);
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
                {
                    matchMode: savedSearchMatchMode(
                        response.data.query.filters?.relevance,
                    ),
                    minusKeywords: response.data.query.minus_keywords ?? [],
                },
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

    const openSavedSearchRun = (
        savedSearch: SavedSearchDto,
        result: SavedSearchRunResult<TenderDto>,
    ): void => {
        setCurrentTenders(result.tenders);
        setHistoryTenders((current) => mergeTenders(result.tenders, current));
        setSearchContext({
            query: result.only_new
                ? `${savedSearch.phrase} · только новые`
                : savedSearch.phrase,
            itemsSeen: result.run.items_seen,
            itemsMatched: result.only_new
                ? result.run.new_count
                : result.run.items_matched,
            itemsCreated: result.only_new
                ? result.run.new_count
                : result.run.items_created,
            pagesRequested: result.run.pages_requested,
            pagesLoaded: result.run.pages_loaded,
            partiallyLoaded: result.run.partially_loaded,
            matchMode: savedSearchMatchMode(savedSearch.filters?.relevance),
            minusKeywords: savedSearch.minus_keywords ?? [],
        });
        setCollection('current');
        setView('inbox');
        setSelectionMode(false);
        setSelectedTenderIds([]);
        setSearchNotice(
            result.only_new
                ? `Открыт запуск «${savedSearch.name}»: новых карточек относительно предыдущего запуска — ${result.run.new_count}.`
                : `Открыт сохранённый запуск «${savedSearch.name}».`,
        );
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
            <Head title="Поиск ЕИС" />
            <AppShell
                activeNav="/mvp/workspace"
                className="mvp-workspace"
                eyebrow="ЕИС · расширенный поиск"
                role="super_admin"
                title="Поиск тендеров"
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
                        <div className="mvp-workspace__relevance-grid">
                            <label className="form-field">
                                <span>Как сопоставлять фразу</span>
                                <select
                                    onChange={(event) =>
                                        setSearchMatchMode(
                                            event.target.value as SearchMatchMode,
                                        )
                                    }
                                    value={searchMatchMode}
                                >
                                    <option value="all">Все слова</option>
                                    <option value="any">Любое слово</option>
                                    <option value="exact">Точная фраза</option>
                                </select>
                            </label>
                            <label className="form-field">
                                <span>Исключить слова или фразы</span>
                                <input
                                    maxLength={1000}
                                    onChange={(event) =>
                                        setSearchMinusKeywords(event.target.value)
                                    }
                                    placeholder="например, строительство, бумажная продукция"
                                    value={searchMinusKeywords}
                                />
                                <small>
                                    Разделяйте запятыми. Проверка выполняется по
                                    предмету закупки после ответа ЕИС.
                                </small>
                            </label>
                        </div>
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
                            <div className="mvp-workspace__source-group">
                                <strong id="eis-stage-label">Этап закупки</strong>
                                <div
                                    aria-labelledby="eis-stage-label"
                                    className="mvp-workspace__source-options mvp-workspace__source-options--compact"
                                    role="group"
                                >
                                    <label>
                                        <input
                                            checked={searchStages.application}
                                            onChange={(event) =>
                                                setSearchStages((current) => ({
                                                    ...current,
                                                    application: event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>Подача заявок</span>
                                    </label>
                                    <label>
                                        <input
                                            checked={searchStages.commission}
                                            onChange={(event) =>
                                                setSearchStages((current) => ({
                                                    ...current,
                                                    commission: event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>Работа комиссии</span>
                                    </label>
                                    <label>
                                        <input
                                            checked={searchStages.completed}
                                            onChange={(event) =>
                                                setSearchStages((current) => ({
                                                    ...current,
                                                    completed: event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>Закупка завершена</span>
                                    </label>
                                    <label>
                                        <input
                                            checked={searchStages.cancelled}
                                            onChange={(event) =>
                                                setSearchStages((current) => ({
                                                    ...current,
                                                    cancelled: event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>Закупка отменена</span>
                                    </label>
                                </div>
                            </div>
                            <details className="mvp-workspace__source-details">
                                <summary>Дополнительная информация</summary>
                                <div className="mvp-workspace__source-options">
                                    <label>
                                        <input
                                            checked={searchAdditionalInfo.jointPurchase}
                                            onChange={(event) =>
                                                setSearchAdditionalInfo((current) => ({
                                                    ...current,
                                                    jointPurchase: event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>Закупка является совместной</span>
                                    </label>
                                    <label>
                                        <input
                                            checked={
                                                searchAdditionalInfo.placedBySeparateSubdivision
                                            }
                                            onChange={(event) =>
                                                setSearchAdditionalInfo((current) => ({
                                                    ...current,
                                                    placedBySeparateSubdivision:
                                                        event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>
                                            Извещение размещено обособленным
                                            подразделением заказчика (223-ФЗ)
                                        </span>
                                    </label>
                                    <label>
                                        <input
                                            checked={
                                                searchAdditionalInfo.unionStateBudget
                                            }
                                            onChange={(event) =>
                                                setSearchAdditionalInfo((current) => ({
                                                    ...current,
                                                    unionStateBudget:
                                                        event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>
                                            За счёт средств бюджета Союзного государства
                                            (44-ФЗ)
                                        </span>
                                    </label>
                                    <label>
                                        <input
                                            checked={
                                                searchAdditionalInfo.createdByCustomerRepresentative
                                            }
                                            onChange={(event) =>
                                                setSearchAdditionalInfo((current) => ({
                                                    ...current,
                                                    createdByCustomerRepresentative:
                                                        event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>
                                            Закупка создана представителем заказчика
                                            (223-ФЗ)
                                        </span>
                                    </label>
                                    <label>
                                        <input
                                            checked={searchAdditionalInfo.smpSono}
                                            onChange={(event) =>
                                                setSearchAdditionalInfo((current) => ({
                                                    ...current,
                                                    smpSono: event.target.checked,
                                                }))
                                            }
                                            type="checkbox"
                                        />
                                        <span>Закупка у СМП и СОНО</span>
                                    </label>
                                </div>
                            </details>
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
                            <EisCatalogFilters
                                okpd2WithNested={searchOkpd2WithNested}
                                onOkpd2Change={setSearchOkpd2}
                                onOkpd2WithNestedChange={setSearchOkpd2WithNested}
                                onRegionsChange={setSearchRegions}
                                regions={eisRegions}
                                selectedOkpd2={searchOkpd2}
                                selectedRegions={searchRegions}
                            />
                            <small>
                                {hasManualRssUrl
                                    ? 'Ручная RSS-ссылка уже содержит условия ЕИС и заменяет поля выше.'
                                    : 'Регион передаётся по официальному КЛАДР-коду, ОКПД2 — вместе с внутренним идентификатором справочника ЕИС.'}
                            </small>
                        </fieldset>
                        <details className="mvp-workspace__advanced-search">
                            <summary>Расширенные фильтры ЕИС</summary>
                            <p>
                                Необязательно: вставьте готовую RSS-ссылку ЕИС, если в
                                ней есть условия, которых ещё нет в форме выше.
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
                        <div className="mvp-workspace__toolbar-actions">
                            <Button
                                disabled={
                                    visibleTenders.length === 0 ||
                                    exportingFormat !== null
                                }
                                onClick={() => exportTenders('csv')}
                                size="sm"
                                variant="ghost"
                            >
                                {exportingFormat === 'csv' ? 'CSV…' : 'CSV'}
                            </Button>
                            <Button
                                disabled={
                                    visibleTenders.length === 0 ||
                                    exportingFormat !== null
                                }
                                onClick={() => exportTenders('xlsx')}
                                size="sm"
                                variant="ghost"
                            >
                                {exportingFormat === 'xlsx' ? 'XLSX…' : 'XLSX'}
                            </Button>
                            <Button
                                disabled={collectionTenders.length === 0}
                                onClick={toggleSelectionMode}
                                size="sm"
                                variant={selectionMode ? 'secondary' : 'ghost'}
                            >
                                {selectionMode ? 'Отменить выбор' : 'Выбрать'}
                            </Button>
                        </div>
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
                                        selectedTenderIds.length < 2 ||
                                        selectedTenderIds.length > 5
                                    }
                                    onClick={() => setComparisonOpen(true)}
                                    size="sm"
                                >
                                    Сравнить 2–5
                                </Button>
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
                    {selectionMode && selectedTenderIds.length > 5 ? (
                        <FieldError>
                            Для сравнения оставьте не более пяти карточек. Групповые
                            действия по-прежнему доступны для всего выбора.
                        </FieldError>
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
                        <label className="form-field">
                            <span>Личный тег</span>
                            <input
                                onChange={(event) => setTagFilter(event.target.value)}
                                placeholder="например, приоритет"
                                value={tagFilter}
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
                        <SelectField
                            label="Сортировка выдачи"
                            onChange={(event) =>
                                setTenderSort(event.target.value as TenderSort)
                            }
                            options={[
                                {
                                    value: 'published_desc',
                                    label: 'Сначала опубликованные недавно',
                                },
                                {
                                    value: 'deadline_asc',
                                    label: 'Ближайший срок подачи',
                                },
                                {
                                    value: 'budget_desc',
                                    label: 'Сначала высокая НМЦК',
                                },
                                {
                                    value: 'budget_asc',
                                    label: 'Сначала низкая НМЦК',
                                },
                                {
                                    value: 'favorite_first',
                                    label: 'Сначала избранные',
                                },
                                {
                                    value: 'new_first',
                                    label: 'Сначала новые',
                                },
                            ]}
                            value={tenderSort}
                        />
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
                                            {savedSearchFilterLabel(savedSearch)}
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
                                            onClick={() =>
                                                setHistorySearchId((current) =>
                                                    current === savedSearch.id
                                                        ? null
                                                        : savedSearch.id,
                                                )
                                            }
                                            size="sm"
                                            variant="secondary"
                                        >
                                            {historySearchId === savedSearch.id
                                                ? 'Скрыть историю'
                                                : 'История запусков'}
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
                                    {historySearchId === savedSearch.id ? (
                                        <SavedSearchRunHistory<TenderDto>
                                            onOpenRun={(result) =>
                                                openSavedSearchRun(savedSearch, result)
                                            }
                                            queryId={savedSearch.id}
                                        />
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    ) : null}
                </GlassCard>

                <p className="mvp-workspace__source-note">
                    Источник — RSS расширенного поиска ЕИС. По фразе ссылка создаётся
                    автоматически; ручная RSS-ссылка нужна только для условий, которых
                    нет во встроенной форме. Автоматический мониторинг и
                    Telegram-уведомления пока не включены.
                </p>
            </AppShell>
            <TenderComparison
                onClose={() => setComparisonOpen(false)}
                open={comparisonOpen}
                tenders={comparisonTenders}
            />
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
            {tender.tags.length > 0 ? (
                <div className="mvp-tender-card__tags" aria-label="Личные теги">
                    {tender.tags.map((tag) => (
                        <span key={tag}>{tag}</span>
                    ))}
                </div>
            ) : null}
            {tender.next_action_on ? (
                <p className="mvp-tender-card__next-action">
                    Следующее действие: {formatDate(tender.next_action_on)}
                </p>
            ) : null}
            {tender.note ? (
                <p className="mvp-tender-card__note">Моя заметка: {tender.note}</p>
            ) : null}
            {tender.match_reason ? (
                <div className="mvp-tender-card__match-reason">
                    <strong>Почему в выдаче</strong>
                    <span>{matchReasonLabel(tender.match_reason)}</span>
                    {tender.match_reason.minus_keywords_checked.length > 0 ? (
                        <span>
                            Исключения не найдены:{' '}
                            {tender.match_reason.minus_keywords_checked.join(', ')}
                        </span>
                    ) : null}
                </div>
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

function compareTenders(left: TenderDto, right: TenderDto, sort: TenderSort): number {
    switch (sort) {
        case 'deadline_asc':
            return compareNullableDates(left.deadline_at, right.deadline_at, 'asc');
        case 'budget_desc':
            return compareNullableNumbers(
                left.budget_amount,
                right.budget_amount,
                'desc',
            );
        case 'budget_asc':
            return compareNullableNumbers(
                left.budget_amount,
                right.budget_amount,
                'asc',
            );
        case 'favorite_first':
            return (
                Number(right.status === 'favorite') - Number(left.status === 'favorite')
            );
        case 'new_first':
            return Number(right.status === 'new') - Number(left.status === 'new');
        default:
            return compareNullableDates(left.published_at, right.published_at, 'desc');
    }
}

function readTenderSort(): TenderSort {
    if (typeof window === 'undefined') return 'published_desc';

    const value = window.localStorage.getItem('tender-finder:workspace-sort');
    const sorts: TenderSort[] = [
        'published_desc',
        'deadline_asc',
        'budget_desc',
        'budget_asc',
        'favorite_first',
        'new_first',
    ];

    return sorts.includes(value as TenderSort)
        ? (value as TenderSort)
        : 'published_desc';
}

function compareNullableDates(
    left: string | null,
    right: string | null,
    direction: 'asc' | 'desc',
): number {
    if (left === null) return right === null ? 0 : 1;
    if (right === null) return -1;

    const compared = new Date(left).getTime() - new Date(right).getTime();

    return direction === 'asc' ? compared : -compared;
}

function compareNullableNumbers(
    left: string | null,
    right: string | null,
    direction: 'asc' | 'desc',
): number {
    if (left === null) return right === null ? 0 : 1;
    if (right === null) return -1;

    const compared = Number(left) - Number(right);

    return direction === 'asc' ? compared : -compared;
}

function savedSourceFilters({
    law44,
    law223,
    stages,
    additionalInfo,
    budgetFrom,
    budgetTo,
    publishedFrom,
    publishedTo,
    regions,
    okpd2,
    okpd2WithNested,
    pages,
    rssUrl,
}: {
    law44: boolean;
    law223: boolean;
    stages: SearchStages;
    additionalInfo: SearchAdditionalInfo;
    budgetFrom: string;
    budgetTo: string;
    publishedFrom: string;
    publishedTo: string;
    regions: EisRegionOption[];
    okpd2: EisOkpd2Option[];
    okpd2WithNested: boolean;
    pages: string;
    rssUrl: string;
}): SavedSourceFilters {
    const manualRssUrl = rssUrl.trim();

    return {
        law_44: manualRssUrl === '' ? law44 : false,
        law_223: manualRssUrl === '' ? law223 : false,
        stage_application: manualRssUrl === '' ? stages.application : false,
        stage_commission: manualRssUrl === '' ? stages.commission : false,
        stage_completed: manualRssUrl === '' ? stages.completed : false,
        stage_cancelled: manualRssUrl === '' ? stages.cancelled : false,
        joint_purchase: manualRssUrl === '' ? additionalInfo.jointPurchase : false,
        placed_by_separate_subdivision:
            manualRssUrl === '' ? additionalInfo.placedBySeparateSubdivision : false,
        union_state_budget:
            manualRssUrl === '' ? additionalInfo.unionStateBudget : false,
        created_by_customer_representative:
            manualRssUrl === ''
                ? additionalInfo.createdByCustomerRepresentative
                : false,
        smp_sono: manualRssUrl === '' ? additionalInfo.smpSono : false,
        budget_from: manualRssUrl === '' ? budgetFrom || null : null,
        budget_to: manualRssUrl === '' ? budgetTo || null : null,
        published_from: manualRssUrl === '' ? publishedFrom || null : null,
        published_to: manualRssUrl === '' ? publishedTo || null : null,
        regions: manualRssUrl === '' ? regions : [],
        okpd2: manualRssUrl === '' ? okpd2 : [],
        okpd2_with_nested: manualRssUrl === '' ? okpd2WithNested : true,
        pages: Number(pages) || 3,
        rss_url: manualRssUrl || null,
    };
}

function savedSearchStages(source?: SavedSourceFilters): SearchStages {
    if (source?.rss_url) {
        return defaultSearchStages();
    }

    const hasStoredStages = [
        'stage_application',
        'stage_commission',
        'stage_completed',
        'stage_cancelled',
    ].some((key) => Object.prototype.hasOwnProperty.call(source ?? {}, key));

    if (!hasStoredStages) {
        return defaultSearchStages();
    }

    return {
        application: Boolean(source?.stage_application),
        commission: Boolean(source?.stage_commission),
        completed: Boolean(source?.stage_completed),
        cancelled: Boolean(source?.stage_cancelled),
    };
}

function savedSearchFilterLabel(savedSearch: SavedSearchDto): string {
    const source = savedSearch.filters?.source;
    const mode = savedSearchMatchMode(savedSearch.filters?.relevance);
    const parts: string[] = [matchModeLabel(mode)];

    if ((savedSearch.minus_keywords ?? []).length > 0) {
        parts.push(`Исключений: ${savedSearch.minus_keywords?.length ?? 0}`);
    }

    if (!source) {
        return parts.join(' · ');
    }

    if (source.rss_url) {
        parts.push(`Ручная RSS-ссылка ЕИС · до ${source.pages ?? 3} стр.`);

        return parts.join(' · ');
    }

    if (source.law_44) {
        parts.push('44-ФЗ');
    }

    if (source.law_223) {
        parts.push('223-ФЗ');
    }

    const stages = savedSearchStages(source);
    const selectedStages = [
        stages.application ? 'подача заявок' : null,
        stages.commission ? 'работа комиссии' : null,
        stages.completed ? 'завершена' : null,
        stages.cancelled ? 'отменена' : null,
    ].filter(Boolean);
    parts.push(
        selectedStages.length === 4
            ? 'Все этапы'
            : `Этапы: ${selectedStages.join(', ')}`,
    );

    const additionalCount = [
        source.joint_purchase,
        source.placed_by_separate_subdivision,
        source.union_state_budget,
        source.created_by_customer_representative,
        source.smp_sono,
    ].filter(Boolean).length;

    if (additionalCount > 0) {
        parts.push(`Доп. условия: ${additionalCount}`);
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

    if ((source.regions ?? []).length > 0) {
        parts.push(
            `Регионы: ${source.regions?.map((region) => region.name).join(', ')}`,
        );
    }

    if ((source.okpd2 ?? []).length > 0) {
        parts.push(`ОКПД2: ${source.okpd2?.map((item) => item.code).join(', ')}`);
    }

    parts.push(`до ${source.pages ?? 3} RSS-стр.`);

    return parts.join(' · ');
}

function savedSearchMatchMode(relevance?: SavedRelevanceFilters): SearchMatchMode {
    return relevance?.match_mode === 'any' || relevance?.match_mode === 'exact'
        ? relevance.match_mode
        : 'all';
}

function matchModeLabel(mode: SearchMatchMode): string {
    return {
        all: 'Все слова',
        any: 'Любое слово',
        exact: 'Точная фраза',
    }[mode];
}

function parseMinusKeywords(value: string): string[] {
    const unique = new Map<string, string>();

    value
        .split(/[,;\n]+/)
        .map((keyword) => keyword.trim())
        .filter(Boolean)
        .forEach((keyword) => {
            unique.set(keyword.toLocaleLowerCase('ru-RU'), keyword);
        });

    return [...unique.values()];
}

function matchReasonLabel(reason: TenderMatchReason): string {
    const terms = reason.matched_terms.join(', ');

    return reason.mode === 'exact'
        ? `Найдена точная фраза: «${terms}»`
        : reason.mode === 'any'
          ? `Совпало хотя бы одно слово: ${terms}`
          : `Совпали все слова: ${terms}`;
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

function exportFilterSummary({
    collection,
    searchContext,
    view,
    regionFilter,
    budgetMin,
    budgetMax,
    tagFilter,
    selectedCount,
}: {
    collection: TenderCollection;
    searchContext: SearchContext | null;
    view: TenderView;
    regionFilter: string;
    budgetMin: string;
    budgetMax: string;
    tagFilter: string;
    selectedCount: number;
}): string {
    const parts = [
        collection === 'current' ? 'Текущая выдача' : 'История',
        searchContext ? `Запрос: ${searchContext.query}` : null,
        view !== 'inbox' ? `Статус: ${statusLabel(view)}` : null,
        regionFilter.trim() ? `Регион/название: ${regionFilter.trim()}` : null,
        budgetMin ? `НМЦК от ${budgetMin}` : null,
        budgetMax ? `НМЦК до ${budgetMax}` : null,
        tagFilter.trim() ? `Тег: ${tagFilter.trim()}` : null,
        selectedCount > 0 ? `Выбрано вручную: ${selectedCount}` : null,
    ].filter((part): part is string => part !== null);

    return parts.join(' · ').slice(0, 500);
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
                      ? noRelevantTenderDescription(searchContext)
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

function noRelevantTenderDescription(searchContext: SearchContext): string {
    const rule =
        searchContext.matchMode === 'exact'
            ? 'не содержит точной фразы'
            : searchContext.matchMode === 'any'
              ? 'не содержит ни одного слова запроса'
              : 'не содержит всех слов запроса';
    const exclusions =
        searchContext.minusKeywords.length > 0
            ? ' или содержит одно из заданных исключений'
            : '';

    return `ЕИС вернула ${searchContext.itemsSeen}, но каждая карточка ${rule}${exclusions} в предмете закупки.`;
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
    const errors = response?.data?.errors;
    const fieldMessage =
        errors?.query?.[0] ??
        errors?.match_mode?.[0] ??
        errors?.minus_keywords?.[0] ??
        errors?.url?.[0] ??
        errors?.pages?.[0] ??
        errors?.budget_from?.[0] ??
        errors?.budget_to?.[0] ??
        errors?.published_from?.[0] ??
        errors?.published_to?.[0] ??
        Object.values(errors ?? {}).flat()[0];

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
