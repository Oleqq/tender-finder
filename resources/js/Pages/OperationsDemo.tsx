import { Head, Link, usePage } from '@inertiajs/react';
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
import type { PageProps } from '../types';

type AdminView = 'overview' | 'ops';

type AccessMetrics = {
    registered: number;
    preview: number;
    trialing: number;
    paid: number;
    granted: number;
    expired: number;
};

type OperationsProps = { accessMetrics: AccessMetrics };

export default function OperationsDemo() {
    const { accessMetrics } = usePage<PageProps<OperationsProps>>().props;
    const [view, setView] = useState<AdminView>('overview');
    const [loadingPreview, setLoadingPreview] = useState(false);

    return (
        <>
            <Head title="Операции" />
            <AppShell
                activeNav="/operations-demo"
                backHref="/profile"
                eyebrow="Super-admin · read-only"
                role="super_admin"
                title="Операции"
            >
                <section className="operations-intro page-enter">
                    <Badge tone="accent">Доступ и воронка</Badge>
                    <h2>Состояние клиентской базы.</h2>
                    <p>
                        Только агрегаты по верифицированным Telegram-пользователям. Роли
                        не смешиваются с состоянием trial и оплаты.
                    </p>
                </section>

                <InlineAlert title="Без персональных данных" tone="neutral">
                    Экран не показывает Telegram ID, имена или поисковые фразы. Он
                    только считает текущие состояния доступа на сервере.
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
                    <Overview loading={loadingPreview} metrics={accessMetrics} />
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

function Overview({ loading, metrics }: { loading: boolean; metrics: AccessMetrics }) {
    return (
        <section aria-label="Demo Overview" className="operations-content page-enter">
            <div className="section-heading">
                <div>
                    <p>Текущие агрегаты</p>
                    <h2>Overview</h2>
                </div>
                <Badge tone="accent">сейчас</Badge>
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
                <div
                    aria-label="Метрики доступа"
                    className="metric-grid metric-grid--two"
                >
                    <MetricCard
                        detail="проверенные Telegram ID"
                        icon="user"
                        label="Зарегистрированы"
                        value={String(metrics.registered)}
                    />
                    <MetricCard
                        detail="активный пробный доступ"
                        icon="spark"
                        label="На trial"
                        value={String(metrics.trialing)}
                    />
                    <MetricCard
                        detail="активная оплата через Telegram Stars"
                        icon="chart"
                        label="Оплатили подписку"
                        value={String(metrics.paid)}
                    />
                    <MetricCard
                        accent
                        detail="доступ выдан администратором"
                        icon="layers"
                        label="Ручные доступы"
                        value={String(metrics.granted)}
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
                            detail="вошли через Telegram, но не начали trial"
                            icon="user"
                            label="Preview"
                            value={String(metrics.preview)}
                        />
                        <DataRow
                            detail="trial или доступ завершился, активной подписки нет"
                            icon="chart"
                            label="Истёк доступ"
                            value={String(metrics.expired)}
                        />
                        <DataRow
                            detail="источник оплаты и возвраты появятся после платёжного контура"
                            icon="bell"
                            label="Выручка"
                            value="не подключено"
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
