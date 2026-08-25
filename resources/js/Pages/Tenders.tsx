import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { TenderCard } from '../Components/TenderCard';
import {
    Badge,
    BottomSheet,
    Button,
    Combobox,
    DateRangeInput,
    EmptyState,
    FilterChip,
    GlassCard,
    MoneyRangeInput,
    MultiSelect,
    RetryState,
    SearchInput,
    SelectField,
    TenderCardSkeleton,
    Toast,
} from '../Components/ui';
import type { PageProps } from '../types';

type FeedState = 'ready' | 'loading' | 'error' | 'offline';

type TenderMatch = {
    id: number;
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
    match_reasons: string[];
};

type TendersPageProps = PageProps<{
    mode?: 'demo' | 'live';
    tenderMatches?: TenderMatch[];
}>;

export default function Tenders() {
    const { mode = 'demo', tenderMatches = [] } = usePage<TendersPageProps>().props;

    return mode === 'live' ? (
        <LiveTenderFeed tenderMatches={tenderMatches} />
    ) : (
        <DemoTenders />
    );
}

function DemoTenders() {
    const { auth } = usePage<PageProps>().props;
    const [selectedFilter, setSelectedFilter] = useState('Все');
    const [feedState, setFeedState] = useState<FeedState>('ready');
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [toastVisible, setToastVisible] = useState(false);
    const [topic, setTopic] = useState('');
    const [region, setRegion] = useState('all');
    const [directions, setDirections] = useState<string[]>([]);
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [minBudget, setMinBudget] = useState('');
    const [maxBudget, setMaxBudget] = useState('');
    const [formError, setFormError] = useState('');

    useEffect(() => {
        if (!toastVisible) {
            return;
        }

        const timeout = window.setTimeout(() => setToastVisible(false), 2600);
        return () => window.clearTimeout(timeout);
    }, [toastVisible]);

    const submitFilters = (): void => {
        if (directions.length === 0) {
            setFormError('Выберите хотя бы одно направление для этого demo-примера.');
            return;
        }

        setFormError('');
        setFiltersOpen(false);
        setToastVisible(true);
    };

    return (
        <>
            <Head title="Мои тендеры" />
            <AppShell
                action={
                    <button
                        aria-label="Открыть demo-фильтры"
                        className="icon-button"
                        onClick={() => setFiltersOpen(true)}
                        type="button"
                    >
                        <Icon name="filter" size={20} />
                    </button>
                }
                activeNav="/tenders"
                eyebrow="Мой поток"
                title="Тендеры"
            >
                <section className="tenders-tools page-enter">
                    <SearchInput
                        aria-label="Поиск по demo-тендерам"
                        placeholder="Поиск — demo"
                    />
                    <div aria-label="Фильтры тендеров" className="filter-scroll">
                        {['Все', 'Новые', 'В фокусе'].map((filter) => (
                            <FilterChip
                                active={selectedFilter === filter}
                                key={filter}
                                onClick={() => setSelectedFilter(filter)}
                            >
                                {filter}
                            </FilterChip>
                        ))}
                    </div>
                </section>

                <GlassCard
                    className="tenders-summary page-enter page-enter--delay"
                    tone="quiet"
                >
                    <span className="tenders-summary__mark">
                        <Icon name="layers" size={19} />
                    </span>
                    <div>
                        <p>Состояния интерфейса</p>
                        <strong>Выберите образец состояния</strong>
                    </div>
                    <Badge tone="neutral">demo</Badge>
                </GlassCard>

                <div
                    aria-label="Demo-состояние потока"
                    className="filter-scroll tenders-state-switch"
                >
                    {(
                        [
                            ['ready', 'Пусто'],
                            ['loading', 'Loading'],
                            ['error', 'Ошибка'],
                            ['offline', 'Offline'],
                        ] as Array<[FeedState, string]>
                    ).map(([state, label]) => (
                        <FilterChip
                            active={feedState === state}
                            key={state}
                            onClick={() => setFeedState(state)}
                        >
                            {label}
                        </FilterChip>
                    ))}
                </div>

                <FeedPreview
                    onRetry={() => setFeedState('ready')}
                    selectedFilter={selectedFilter}
                    state={feedState}
                />

                <section className="empty-hint page-enter page-enter--later">
                    <Icon name="spark" size={17} />
                    <p>
                        {auth.user
                            ? 'Telegram-сессия подтверждена. Создайте настоящий мониторинг в защищённом разделе.'
                            : 'Все значения и состояния на этом экране — образцы. Сохранение фильтров доступно только после server-side Telegram-сессии.'}
                    </p>
                    {auth.user ? (
                        <Link className="empty-hint__link" href="/queries">
                            К мониторингам <Icon name="chevron-right" size={16} />
                        </Link>
                    ) : null}
                </section>

                <BottomSheet
                    onClose={() => setFiltersOpen(false)}
                    open={filtersOpen}
                    title="Фильтры · demo"
                >
                    <p className="sheet-description">
                        Поля показывают будущую форму запроса. Ничего не сохраняется и
                        не отправляется на сервер.
                    </p>
                    <div className="filter-form">
                        <Combobox
                            label="Тема поиска"
                            onChange={setTopic}
                            options={['Цифровые сервисы', 'Строительство', 'Обучение']}
                            placeholder="Например, цифровые сервисы"
                            value={topic}
                        />
                        <SelectField
                            label="Регион"
                            onChange={(event) => setRegion(event.target.value)}
                            options={[
                                { value: 'all', label: 'Все регионы' },
                                { value: 'moscow', label: 'Москва' },
                                { value: 'spb', label: 'Санкт-Петербург' },
                                { value: 'ural', label: 'Уральский ФО' },
                            ]}
                            value={region}
                        />
                        <MultiSelect
                            error={formError || undefined}
                            label="Направления"
                            onChange={(value) => {
                                setDirections(value);
                                if (formError) {
                                    setFormError('');
                                }
                            }}
                            options={['IT', 'Маркетинг', 'Строительство']}
                            selected={directions}
                        />
                        <DateRangeInput
                            end={endDate}
                            onEndChange={setEndDate}
                            onStartChange={setStartDate}
                            start={startDate}
                        />
                        <MoneyRangeInput
                            max={maxBudget}
                            min={minBudget}
                            onMaxChange={setMaxBudget}
                            onMinChange={setMinBudget}
                        />
                    </div>
                    <Button
                        className="sheet-action"
                        icon="check"
                        onClick={submitFilters}
                    >
                        Применить в demo
                    </Button>
                </BottomSheet>
                <Toast
                    message="Фильтры применены только в текущем demo-интерфейсе"
                    tone="neutral"
                    visible={toastVisible}
                />
            </AppShell>
        </>
    );
}

function LiveTenderFeed({ tenderMatches }: { tenderMatches: TenderMatch[] }) {
    return (
        <>
            <Head title="Мои тендеры" />
            <AppShell activeNav="/tenders" eyebrow="Мой поток" title="Тендеры">
                <GlassCard className="tenders-summary page-enter" tone="quiet">
                    <span className="tenders-summary__mark">
                        <Icon name="layers" size={19} />
                    </span>
                    <div>
                        <p>Совпадения по мониторингам</p>
                        <strong>
                            {tenderMatches.length === 0
                                ? 'Новых карточек пока нет'
                                : `${tenderMatches.length} ${tenderWord(tenderMatches.length)} в потоке`}
                        </strong>
                    </div>
                    <Badge tone="neutral">сервер</Badge>
                </GlassCard>

                {tenderMatches.length > 0 ? (
                    <section
                        aria-label="Совпавшие тендеры"
                        className="tenders-preview page-enter page-enter--delay"
                    >
                        {tenderMatches.map((match) => (
                            <TenderCard
                                customer={`Мониторинг: ${match.query_name}`}
                                deadline={formatDeadline(match.deadline_at)}
                                description={match.description}
                                href={match.canonical_url}
                                key={match.id}
                                match={`Совпало: ${match.match_reasons.join(', ')}`}
                                price={formatBudget(
                                    match.budget_amount,
                                    match.currency,
                                )}
                                status="Новый"
                                title={match.title}
                            />
                        ))}
                    </section>
                ) : (
                    <div className="tenders-empty page-enter page-enter--later">
                        <EmptyState
                            action={
                                <Link
                                    className="button button--secondary"
                                    href="/queries"
                                >
                                    <Icon name="tenders" size={18} />
                                    <span>Открыть мониторинги</span>
                                </Link>
                            }
                            description="Когда новая закупка совпадёт с активным мониторингом, сервер сохранит её здесь вместе с понятной причиной совпадения."
                            icon="compass"
                            title="Пока нет подходящих закупок"
                        />
                    </div>
                )}

                <section className="empty-hint page-enter page-enter--later">
                    <Icon name="spark" size={17} />
                    <p>
                        Карточки показывают только совпадения по вашим мониторингам. Это
                        не рейтинг и не обещание шанса на победу.
                    </p>
                </section>
            </AppShell>
        </>
    );
}

function tenderWord(count: number): string {
    const remainder = count % 10;
    const teen = count % 100;

    if (teen >= 11 && teen <= 14) {
        return 'карточек';
    }

    if (remainder === 1) {
        return 'карточка';
    }

    if (remainder >= 2 && remainder <= 4) {
        return 'карточки';
    }

    return 'карточек';
}

function formatBudget(value: string | null, currency: string): string {
    if (value === null) {
        return 'Сумма не указана';
    }

    return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Number(value))} ${currency === 'RUB' ? '₽' : currency}`;
}

function formatDeadline(value: string | null): string {
    if (value === null) {
        return 'Срок не указан';
    }

    return `Срок: ${new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(new Date(value))}`;
}

function FeedPreview({
    state,
    selectedFilter,
    onRetry,
}: {
    state: FeedState;
    selectedFilter: string;
    onRetry: () => void;
}) {
    if (state === 'loading') {
        return (
            <section
                aria-label="Loading demo-потока"
                className="tenders-preview page-enter"
            >
                <TenderCardSkeleton />
                <TenderCardSkeleton />
            </section>
        );
    }

    if (state === 'error') {
        return (
            <section className="tenders-preview page-enter">
                <RetryState
                    action={
                        <Button icon="refresh" onClick={onRetry} variant="secondary">
                            Повторить demo
                        </Button>
                    }
                    description="Пример ошибки загрузки. Будущий запрос повторит только безопасное чтение данных."
                    title="Не удалось обновить поток"
                />
            </section>
        );
    }

    if (state === 'offline') {
        return (
            <section className="tenders-preview page-enter">
                <RetryState
                    action={
                        <Button icon="refresh" onClick={onRetry} variant="secondary">
                            Проверить снова
                        </Button>
                    }
                    description="Пример offline-state: локальные правки не отправляются до восстановления соединения."
                    title="Нет подключения"
                    tone="offline"
                />
            </section>
        );
    }

    return (
        <div className="tenders-empty page-enter page-enter--later">
            <EmptyState
                action={
                    <Link className="button button--secondary" href="/dashboard">
                        <Icon name="chart" size={18} />
                        <span>Посмотреть обзор</span>
                    </Link>
                }
                description={
                    selectedFilter === 'Все'
                        ? 'Здесь появятся подходящие закупки, когда будет настроен первый мониторинг.'
                        : `В разделе «${selectedFilter}» пока нет тендеров.`
                }
                icon="compass"
                title="Тишина — тоже полезный сигнал"
            />
        </div>
    );
}
