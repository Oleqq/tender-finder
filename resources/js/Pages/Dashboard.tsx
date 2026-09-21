import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard, InlineAlert } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import type { PageProps } from '../types';

type NextAction = {
    tender_id: number;
    title: string;
    reg_number: string | null;
    next_action_on: string;
    status: string;
    tags: string[];
};

type DashboardProps = {
    nextActions: {
        overdue_count: number;
        today_count: number;
        items: NextAction[];
    };
};

export default function Dashboard() {
    const { auth, nextActions } = usePage<PageProps<DashboardProps>>().props;
    const access = presentAccess(auth.access);
    const canUseMonitoring = ['trialing', 'active'].includes(auth.access?.state ?? '');
    const canStartTrial = auth.access?.state === 'preview';
    const accessHref = canStartTrial ? '/consents' : '/plans';

    return (
        <>
            <Head title="Обзор" />
            <AppShell
                activeNav="/dashboard"
                eyebrow="Tender Finder"
                title="Рабочее пространство"
            >
                <section className="dashboard-hero page-enter">
                    <div>
                        <Badge tone={access.tone}>{access.badge}</Badge>
                        <h2>
                            Тендеры
                            <br />
                            <em>в фокусе.</em>
                        </h2>
                        <p>
                            {canUseMonitoring
                                ? 'Создавайте мониторинги и разбирайте только те закупки, которые совпали с вашими условиями.'
                                : canStartTrial
                                  ? 'Примите оферту и политику — сразу включим 3 дня доступа и до трёх мониторингов.'
                                  : 'Профиль готов. Выберите способ продления доступа, чтобы снова создавать мониторинги.'}
                        </p>
                    </div>
                    <span aria-hidden="true" className="dashboard-signal">
                        <Icon name="wave" size={18} />
                        <span>поток</span>
                    </span>
                </section>

                <section className="dashboard-section next-actions page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Сегодня</p>
                            <h2>Что требует внимания</h2>
                        </div>
                        <Link href="/tenders?sort=deadline_asc">
                            Вся лента <Icon name="chevron-right" size={16} />
                        </Link>
                    </div>
                    {nextActions.overdue_count > 0 || nextActions.today_count > 0 ? (
                        <div className="next-actions__summary">
                            {nextActions.overdue_count > 0 ? (
                                <GlassCard tone="danger">
                                    <span>Просрочено</span>
                                    <strong>{nextActions.overdue_count}</strong>
                                </GlassCard>
                            ) : null}
                            {nextActions.today_count > 0 ? (
                                <GlassCard tone="accent">
                                    <span>На сегодня</span>
                                    <strong>{nextActions.today_count}</strong>
                                </GlassCard>
                            ) : null}
                        </div>
                    ) : null}
                    {nextActions.items.length > 0 ? (
                        <div className="next-actions__list">
                            {nextActions.items.map((action) => (
                                <Link
                                    className="next-action"
                                    href={'/local/mvp/tenders/' + action.tender_id}
                                    key={action.tender_id}
                                >
                                    <span className="next-action__date">
                                        <Badge
                                            tone={actionDateTone(action.next_action_on)}
                                        >
                                            {actionDateLabel(action.next_action_on)}
                                        </Badge>
                                    </span>
                                    <span className="next-action__copy">
                                        <strong>{action.title}</strong>
                                        <small>
                                            {action.reg_number
                                                ? '№ ' + action.reg_number
                                                : 'Номер ЕИС не указан'}
                                            {action.tags.length > 0
                                                ? ' · ' + action.tags.join(', ')
                                                : ''}
                                        </small>
                                    </span>
                                    <Icon name="chevron-right" size={17} />
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <InlineAlert title="Срочных действий нет" tone="neutral">
                            Новые совпадения и назначенные действия появятся здесь.
                        </InlineAlert>
                    )}
                </section>

                <section className="dashboard-section page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Следующий шаг</p>
                            <h2>
                                {canUseMonitoring
                                    ? 'Настройте мониторинг'
                                    : canStartTrial
                                      ? 'Начните 3 дня бесплатно'
                                      : 'Продлите доступ'}
                            </h2>
                        </div>
                        <Link href={canUseMonitoring ? '/queries' : accessHref}>
                            {canUseMonitoring
                                ? 'Мониторинги'
                                : canStartTrial
                                  ? 'Начать'
                                  : 'Подробнее'}{' '}
                            <Icon name="chevron-right" size={16} />
                        </Link>
                    </div>
                    <Link
                        className="button button--primary button--md"
                        href={canUseMonitoring ? '/queries' : accessHref}
                    >
                        {canUseMonitoring
                            ? 'Настроить мониторинг'
                            : canStartTrial
                              ? 'Начать 3 дня бесплатно'
                              : 'Посмотреть доступ'}
                    </Link>
                </section>
            </AppShell>
        </>
    );
}

function actionDateLabel(value: string): string {
    const today = localDateKey(new Date());

    if (value < today) return 'Просрочено · ' + formatActionDate(value);
    if (value === today) return 'Сегодня';
    return formatActionDate(value);
}

function actionDateTone(value: string): 'neutral' | 'accent' | 'warning' | 'danger' {
    const today = localDateKey(new Date());

    if (value < today) return 'danger';
    if (value === today) return 'warning';
    return 'accent';
}

function formatActionDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: 'short',
    }).format(new Date(value + 'T00:00:00'));
}

function localDateKey(value: Date): string {
    const year = value.getFullYear();
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return year + '-' + month + '-' + day;
}
