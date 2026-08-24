import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { MetricCard } from '../Components/MetricCard';
import { TenderCard } from '../Components/TenderCard';
import {
    Badge,
    BottomSheet,
    Button,
    GlassCard,
    SegmentedControl,
    Toast,
    Toggle,
} from '../Components/ui';

export default function Dashboard() {
    const [period, setPeriod] = useState('week');
    const [monitoringEnabled, setMonitoringEnabled] = useState(true);
    const [sheetOpen, setSheetOpen] = useState(false);
    const [toastVisible, setToastVisible] = useState(false);

    useEffect(() => {
        if (!toastVisible) {
            return;
        }

        const timeout = window.setTimeout(() => setToastVisible(false), 2600);
        return () => window.clearTimeout(timeout);
    }, [toastVisible]);

    return (
        <>
            <Head title="Обзор" />
            <AppShell
                action={
                    <button
                        aria-label="Настройки мониторинга"
                        className="icon-button"
                        onClick={() => setSheetOpen(true)}
                        type="button"
                    >
                        <Icon name="settings" size={20} />
                    </button>
                }
                activeNav="/dashboard"
                eyebrow="Tender Finder"
                title="Добрый вечер"
            >
                <section className="dashboard-hero page-enter">
                    <div>
                        <Badge tone={monitoringEnabled ? 'success' : 'neutral'}>
                            <span className="status-dot" />{' '}
                            {monitoringEnabled
                                ? 'Мониторинг активен'
                                : 'Мониторинг на паузе'}
                        </Badge>
                        <h2>
                            Ваша воронка
                            <br />
                            <em>спокойна.</em>
                        </h2>
                        <p>
                            {monitoringEnabled
                                ? 'Новые сигналы появятся здесь, как только найдём подходящие закупки.'
                                : 'Включите мониторинг, чтобы не пропустить новые возможности.'}
                        </p>
                    </div>
                    <span aria-hidden="true" className="dashboard-signal">
                        <Icon name="wave" size={18} />
                        <span>demo</span>
                    </span>
                </section>

                <SegmentedControl
                    label="Период демо-метрик"
                    onChange={setPeriod}
                    options={[
                        { value: 'week', label: '7 дней' },
                        { value: 'month', label: '30 дней' },
                    ]}
                    value={period}
                />
                <section
                    aria-label="Метрики"
                    className="metric-grid page-enter page-enter--delay"
                >
                    <MetricCard
                        accent
                        detail={period === 'week' ? '+3 за неделю' : '+11 за месяц'}
                        icon="tenders"
                        label="Найдено"
                        value={period === 'week' ? '12' : '38'}
                    />
                    <MetricCard
                        detail="ожидают настройки"
                        icon="spark"
                        label="В фокусе"
                        value="0"
                    />
                    <MetricCard
                        detail="по вашим темам"
                        icon="chart"
                        label="Совпадение"
                        value="—"
                    />
                </section>

                <GlassCard
                    className="monitor-card page-enter page-enter--later"
                    tone={monitoringEnabled ? 'accent' : 'quiet'}
                >
                    <div className="monitor-card__icon">
                        <Icon name={monitoringEnabled ? 'wave' : 'bell'} size={21} />
                    </div>
                    <div>
                        <p>Статус радара</p>
                        <h3>
                            {monitoringEnabled
                                ? 'Ищем подходящие закупки'
                                : 'Радар ожидает запуска'}
                        </h3>
                        <span>
                            {monitoringEnabled
                                ? 'Демо: обновление каждые 10 минут'
                                : 'Включите, когда будете готовы'}
                        </span>
                    </div>
                    <button
                        aria-label="Открыть настройки радара"
                        className="icon-button icon-button--soft"
                        onClick={() => setSheetOpen(true)}
                        type="button"
                    >
                        <Icon name="chevron-right" size={20} />
                    </button>
                </GlassCard>

                <section className="dashboard-section page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Пример карточки</p>
                            <h2>Как выглядит сигнал</h2>
                        </div>
                        <Link href="/tenders">
                            Все тендеры <Icon name="chevron-right" size={16} />
                        </Link>
                    </div>
                    <TenderCard
                        customer="Городская инфраструктура"
                        deadline="До 18 сентября"
                        match="91% совпадение"
                        price="4,8 млн ₽"
                        status="Новый"
                        title="Разработка цифрового сервиса для жителей"
                    />
                </section>

                <BottomSheet
                    onClose={() => setSheetOpen(false)}
                    open={sheetOpen}
                    title="Настроить радар"
                >
                    <p className="sheet-description">
                        Это интерактивный demo-каркас. Постоянные настройки будут
                        сохранены после подключения серверной части.
                    </p>
                    <Toggle
                        checked={monitoringEnabled}
                        description="Показывать состояние мониторинга"
                        label="Мониторинг тендеров"
                        onChange={setMonitoringEnabled}
                    />
                    <Button
                        className="sheet-action"
                        icon="check"
                        onClick={() => {
                            setSheetOpen(false);
                            setToastVisible(true);
                        }}
                    >
                        Сохранить демо-настройку
                    </Button>
                </BottomSheet>
                <Toast
                    message="Настройка применена в демо-сессии"
                    visible={toastVisible}
                />
            </AppShell>
        </>
    );
}
