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
    const isSuperAdmin = auth.user?.role === 'super_admin';
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

                <GlassCard
                    className="workspace-card page-enter page-enter--delay"
                    tone="accent"
                >
                    <div className="workspace-card__icon">
                        <Icon name="tenders" size={21} />
                    </div>
                    <div>
                        <p>Ваш поток</p>
                        <h3>Совпадения по мониторингам</h3>
                        <span>
                            {canUseMonitoring
                                ? 'Когда сервер найдёт подходящую закупку, карточка появится в ленте с причиной совпадения.'
                                : canStartTrial
                                  ? 'Начните 3 дня бесплатно — затем добавьте первый мониторинг, и здесь появится лента.'
                                  : 'Здесь появится ваша лента после продления доступа и первого мониторинга.'}
                        </span>
                    </div>
                    <Link
                        aria-label={
                            canUseMonitoring
                                ? 'Открыть мои тендеры'
                                : canStartTrial
                                  ? 'Начать 3 дня бесплатно'
                                  : 'Открыть информацию о доступе'
                        }
                        className="icon-button icon-button--soft"
                        href={canUseMonitoring ? '/tenders' : accessHref}
                    >
                        <Icon name="chevron-right" size={20} />
                    </Link>
                </GlassCard>

                <section className="dashboard-section next-actions page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Личный план</p>
                            <h2>Ближайшие действия</h2>
                        </div>
                        <Link href="/tenders?sort=deadline_asc">
                            Вся лента <Icon name="chevron-right" size={16} />
                        </Link>
                    </div>
                    <div className="next-actions__summary">
                        <GlassCard
                            tone={nextActions.overdue_count > 0 ? 'danger' : 'quiet'}
                        >
                            <span>Просрочено</span>
                            <strong>{nextActions.overdue_count}</strong>
                        </GlassCard>
                        <GlassCard
                            tone={nextActions.today_count > 0 ? 'accent' : 'quiet'}
                        >
                            <span>На сегодня</span>
                            <strong>{nextActions.today_count}</strong>
                        </GlassCard>
                    </div>
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
                        <InlineAlert title="Действия пока не назначены" tone="neutral">
                            Откройте карточку тендера и укажите дату следующего действия
                            — она появится здесь.
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
                    <InlineAlert
                        title={canStartTrial ? 'Что будет дальше' : 'Как работает поток'}
                        tone="neutral"
                    >
                        {canUseMonitoring
                            ? 'Мониторинг хранит ваши условия. В ленту попадают только серверные совпадения, а не рекомендации или примерные карточки.'
                            : canStartTrial
                              ? 'После принятия документов trial включится автоматически. Никакую заявку, код или оплату вводить не нужно.'
                              : 'Мы не показываем форму мониторинга, пока нет доступа, и не создаём тестовые данные.'}
                    </InlineAlert>
                </section>

                {isSuperAdmin ? (
                    <GlassCard
                        className="workspace-admin page-enter page-enter--later"
                        tone="quiet"
                    >
                        <div>
                            <p>Дополнительные инструменты</p>
                            <h3>Поиск ЕИС и аналитика продукта</h3>
                            <span>
                                Они доступны только в роли владельца и не меняют ваш
                                пользовательский поток.
                            </span>
                        </div>
                        <div className="workspace-admin__actions">
                            <Link href="/mvp/workspace">
                                Поиск ЕИС <Icon name="chevron-right" size={16} />
                            </Link>
                            <Link href="/operations">
                                Аналитика <Icon name="chevron-right" size={16} />
                            </Link>
                        </div>
                    </GlassCard>
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
