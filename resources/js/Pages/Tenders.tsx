import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
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

export default function Tenders() {
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
