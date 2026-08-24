import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { MetricCard } from '../Components/MetricCard';
import {
    Badge,
    Button,
    DataRow,
    DataRowSkeleton,
    GlassCard,
    InlineAlert,
    MetricCardSkeleton,
    ProgressBar,
    SegmentedControl,
} from '../Components/ui';

type AdminView = 'overview' | 'ops';

export default function OperationsDemo() {
    const [view, setView] = useState<AdminView>('overview');
    const [loadingPreview, setLoadingPreview] = useState(false);

    return (
        <>
            <Head title="Операции · demo" />
            <AppShell
                activeNav="/operations-demo"
                backHref="/profile"
                eyebrow="Super-admin shell"
                role="super_admin"
                title="Операции"
            >
                <section className="operations-intro page-enter">
                    <Badge tone="warning">Только demo</Badge>
                    <h2>Внутренний контур без доступа к данным.</h2>
                    <p>
                        Это read-only образец будущих экранов владельца. Цифры и статусы
                        ниже — примеры интерфейса, не production telemetry.
                    </p>
                </section>

                <InlineAlert title="Реальный доступ пока не создан" tone="warning">
                    В production эти разделы появятся только после server-side проверки
                    Telegram initData и policy для роли super_admin.
                </InlineAlert>

                <SegmentedControl
                    label="Раздел demo-операций"
                    onChange={(value) => setView(value as AdminView)}
                    options={[
                        { value: 'overview', label: 'Overview' },
                        { value: 'ops', label: 'Live Ops' },
                    ]}
                    value={view}
                />

                <div className="operations-toolbar">
                    <Badge tone="neutral">read-only</Badge>
                    <Button
                        icon="refresh"
                        onClick={() => setLoadingPreview((current) => !current)}
                        size="sm"
                        variant="ghost"
                    >
                        {loadingPreview ? 'Показать данные' : 'Показать loading'}
                    </Button>
                </div>

                {view === 'overview' ? (
                    <Overview loading={loadingPreview} />
                ) : (
                    <LiveOps loading={loadingPreview} />
                )}

                <section className="operations-footer">
                    <Icon name="shield" size={18} />
                    <p>
                        Этот демонстрационный экран не назначает роль, не открывает
                        персональные данные и не отправляет команды боту.
                    </p>
                </section>
                <Link className="profile-plans-link" href="/dashboard">
                    Вернуться к клиентскому обзору{' '}
                    <Icon name="chevron-right" size={17} />
                </Link>
            </AppShell>
        </>
    );
}

function Overview({ loading }: { loading: boolean }) {
    return (
        <section aria-label="Demo Overview" className="operations-content page-enter">
            <div className="section-heading">
                <div>
                    <p>Пример агрегатов</p>
                    <h2>Overview</h2>
                </div>
                <Badge tone="accent">demo · 7 дней</Badge>
            </div>
            {loading ? (
                <div
                    aria-label="Загрузка demo-метрик"
                    className="metric-grid metric-grid--two"
                >
                    <MetricCardSkeleton />
                    <MetricCardSkeleton />
                    <MetricCardSkeleton />
                    <MetricCardSkeleton />
                </div>
            ) : (
                <div aria-label="Demo-метрики" className="metric-grid metric-grid--two">
                    <MetricCard
                        detail="пример агрегата"
                        icon="user"
                        label="Новые пользователи"
                        trend={{ value: '+14', label: 'Демо-прирост 14' }}
                        value="48"
                    />
                    <MetricCard
                        detail="пример воронки"
                        icon="spark"
                        label="Trial-активации"
                        trend={{
                            direction: 'neutral',
                            value: '—',
                            label: 'Демо: без динамики',
                        }}
                        value="17"
                    />
                    <MetricCard
                        detail="цены ещё не заданы"
                        icon="chart"
                        label="Paid conversion"
                        trend={{
                            direction: 'neutral',
                            value: 'demo',
                            label: 'Демо-значение',
                        }}
                        value="—"
                    />
                    <MetricCard
                        accent
                        detail="не revenue"
                        icon="layers"
                        label="Stars revenue"
                        trend={{
                            direction: 'neutral',
                            value: 'не подключено',
                            label: 'Не подключено',
                        }}
                        value="—"
                    />
                </div>
            )}

            <GlassCard className="operations-data-list">
                {loading ? (
                    <>
                        <DataRowSkeleton />
                        <DataRowSkeleton />
                        <DataRowSkeleton />
                    </>
                ) : (
                    <>
                        <DataRow
                            detail="Будущий server-side aggregate за фиксированное окно"
                            icon="user"
                            label="Пользователи"
                            value="пример"
                        />
                        <DataRow
                            detail="Сумма только из идемпотентных payment events"
                            icon="chart"
                            label="Stars / refunds"
                            value="не подключено"
                        />
                        <DataRow
                            detail="Нужны campaign и delivery ledger"
                            icon="bell"
                            label="Campaign funnel"
                            value="позже"
                        />
                    </>
                )}
            </GlassCard>
        </section>
    );
}

function LiveOps({ loading }: { loading: boolean }) {
    return (
        <section aria-label="Demo Live Ops" className="operations-content page-enter">
            <div className="section-heading">
                <div>
                    <p>Пример operational state</p>
                    <h2>Live Ops</h2>
                </div>
                <Badge tone="warning">не live</Badge>
            </div>
            <InlineAlert title="Источник данных ещё не подключён" tone="neutral">
                Настоящие active sessions, очереди, heartbeat и latency будут считать
                только серверные read models после PostgreSQL и Redis.
            </InlineAlert>
            <GlassCard className="operations-data-list">
                {loading ? (
                    <>
                        <DataRowSkeleton />
                        <DataRowSkeleton />
                        <DataRowSkeleton />
                        <DataRowSkeleton />
                    </>
                ) : (
                    <>
                        <DataRow
                            detail="Окна 5 / 15 минут задаст сервер"
                            icon="user"
                            label="Active sessions"
                            value={<Badge tone="neutral">нет данных</Badge>}
                        />
                        <DataRow
                            detail="Нужны Redis queue и журнал job attempts"
                            icon="layers"
                            label="Очереди"
                            value={<Badge tone="warning">ожидает</Badge>}
                        />
                        <DataRow
                            detail="Нужны scheduler heartbeat и source runs"
                            icon="wave"
                            label="Свежесть источников"
                            value={<Badge tone="warning">ожидает</Badge>}
                        />
                        <DataRow
                            detail="Считаются только на сервере из webhook events"
                            icon="bell"
                            label="Webhook / latency"
                            value={<Badge tone="neutral">нет данных</Badge>}
                        />
                    </>
                )}
            </GlassCard>
            <ProgressBar
                detail="demo"
                label="Готовность operational read models"
                value={20}
            />
        </section>
    );
}
