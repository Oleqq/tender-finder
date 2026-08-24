import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import {
    Badge,
    EmptyState,
    FilterChip,
    GlassCard,
    SearchInput,
    Toast,
} from '../Components/ui';

export default function Tenders() {
    const [selectedFilter, setSelectedFilter] = useState('Все');
    const [toastVisible, setToastVisible] = useState(false);

    const showDemoToast = (): void => {
        setToastVisible(true);
        window.setTimeout(() => setToastVisible(false), 2600);
    };

    return (
        <>
            <Head title="Мои тендеры" />
            <AppShell
                action={
                    <button
                        aria-label="Фильтры"
                        className="icon-button"
                        onClick={showDemoToast}
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
                    <SearchInput aria-label="Поиск по тендерам" disabled />
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
                        <p>Ваш поток готов</p>
                        <strong>Добавьте первый поисковый фокус</strong>
                    </div>
                    <Badge tone="neutral">demo</Badge>
                </GlassCard>

                <div className="tenders-empty page-enter page-enter--later">
                    <EmptyState
                        action={
                            <Link
                                className="button button--secondary"
                                href="/dashboard"
                            >
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

                <section className="empty-hint page-enter page-enter--later">
                    <Icon name="spark" size={17} />
                    <p>
                        Карточки, поиск и серверные фильтры подключим после появления
                        постоянной базы данных.
                    </p>
                </section>
                <Toast
                    message="Фильтры появятся с первым мониторингом"
                    tone="neutral"
                    visible={toastVisible}
                />
            </AppShell>
        </>
    );
}
