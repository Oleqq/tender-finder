import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard } from '../Components/ui';
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
    workspace: {
        hasMonitoring: boolean;
        hasMatches: boolean;
    };
    nextActions: {
        overdue_count: number;
        today_count: number;
        items: NextAction[];
    };
};

export default function Dashboard() {
    const { auth, nextActions, workspace } = usePage<PageProps<DashboardProps>>().props;
    const access = presentAccess(auth.access);
    const canUseMonitoring = ['trialing', 'active'].includes(auth.access?.state ?? '');
    const canStartTrial = auth.access?.state === 'preview';
    const accessHref = canStartTrial ? '/consents' : '/plans';
    const primaryAction = !canUseMonitoring
        ? {
              href: accessHref,
              label: canStartTrial ? 'Начать 3 дня бесплатно' : 'Посмотреть доступ',
              description: canStartTrial
                  ? 'Примите оферту и политику, чтобы включить пробный доступ и создать первый мониторинг.'
                  : 'Продлите доступ, чтобы снова проверять мониторинги и разбирать закупки.',
          }
        : !workspace.hasMonitoring
          ? {
                href: '/queries',
                label: 'Создать мониторинг',
                description:
                    'Укажите, какие закупки вам нужны, и выберите доступный источник. Состояние первой проверки появится в мониторинге.',
            }
          : !workspace.hasMatches
            ? {
                  href: '/queries',
                  label: 'Проверить мониторинг',
                  description:
                      'Мониторинг создан. Откройте его, чтобы увидеть состояние источника и время следующей проверки.',
              }
            : {
                  href: '/tenders',
                  label: 'Открыть тендеры',
                  description:
                      'В ленте есть совпадения. Откройте карточки и выберите, с какими закупками работать.',
              };

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
                            {!canUseMonitoring
                                ? 'Начните работу'
                                : !workspace.hasMonitoring
                                  ? 'Найдите свои тендеры'
                                  : !workspace.hasMatches
                                    ? 'Поиск запущен'
                                    : 'Тендеры в ленте'}
                        </h2>
                        <p>{primaryAction.description}</p>
                        <Link
                            className="button button--primary button--md dashboard-hero__action"
                            href={primaryAction.href}
                        >
                            {primaryAction.label}
                        </Link>
                    </div>
                </section>

                {nextActions.items.length > 0 ? (
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
                        {nextActions.overdue_count > 0 ||
                        nextActions.today_count > 0 ? (
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
                                                : 'Номер закупки не указан'}
                                            {action.tags.length > 0
                                                ? ' · ' + action.tags.join(', ')
                                                : ''}
                                        </small>
                                    </span>
                                    <Icon name="chevron-right" size={17} />
                                </Link>
                            ))}
                        </div>
                    </section>
                ) : null}
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
