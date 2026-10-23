import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard, InlineAlert } from '../Components/ui';
import { useAccessPresentation } from '../lib/useAccessPresentation';
import type { MiniAppPurchaseState, PageProps } from '../types';

export default function Plans() {
    const { auth, purchase } =
        usePage<PageProps<{ purchase: MiniAppPurchaseState }>>().props;
    const access = useAccessPresentation(auth.access);
    const trial = access.phase === 'trial' || access.phase === 'trial_ending';
    const ended = access.phase === 'expired' || access.phase === 'cancelled';

    return (
        <>
            <Head title="Доступ" />
            <AppShell
                activeNav="/profile"
                backHref="/profile"
                eyebrow="Аккаунт"
                title="Доступ"
            >
                <section className="plans-intro page-enter">
                    <Badge tone={access.tone}>{access.badge}</Badge>
                    <h2>{access.title}</h2>
                    <p>{access.description}</p>
                </section>

                <GlassCard
                    className="access-state-card page-enter page-enter--delay"
                    tone="accent"
                >
                    <div>
                        <span className="access-state-card__icon">
                            <Icon name="shield" size={20} />
                        </span>
                        <p>
                            {trial
                                ? 'Осталось времени'
                                : ended
                                  ? 'Последний период'
                                  : 'Текущий доступ'}
                        </p>
                    </div>
                    <strong>{access.detail}</strong>
                    <span>
                        {trial
                            ? auth.access?.active_query_limit === null ||
                              auth.access?.active_query_limit === undefined
                                ? 'Лимит мониторингов не указан'
                                : `До ${auth.access.active_query_limit} активных мониторингов в пробном периоде`
                            : ended
                              ? 'Мониторинги не проверяют новые тендеры. Настройки и найденные данные сохранены.'
                              : access.phase === 'active'
                                ? auth.access?.active_query_limit === null
                                    ? 'Мониторинги доступны без лимита'
                                    : `До ${auth.access?.active_query_limit} активных мониторингов`
                                : 'Пробный период начнётся после принятия документов'}
                    </span>
                </GlassCard>

                {access.phase === 'trial_ending' ? (
                    <InlineAlert
                        title="Подготовьтесь к окончанию триала"
                        tone="warning"
                    >
                        После указанного времени новые проверки остановятся. Ваши
                        мониторинги и найденные тендеры сохранятся.
                    </InlineAlert>
                ) : null}

                {ended ? (
                    <GlassCard className="access-next-step" tone="quiet">
                        <span className="access-next-step__icon">
                            <Icon name="layers" size={20} />
                        </span>
                        <div>
                            <h2>Ваши данные на месте</h2>
                            <p>
                                Условия поиска, сохранённые тендеры и история работы
                                остаются в аккаунте. Когда доступ будет подтверждён,
                                мониторинги можно будет возобновить.
                            </p>
                            <Link href="/queries">
                                Посмотреть мониторинги{' '}
                                <Icon name="chevron-right" size={16} />
                            </Link>
                        </div>
                    </GlassCard>
                ) : null}

                {access.phase === 'preview' ? (
                    <Link
                        className="button button--primary button--md access-main-action"
                        href="/consents"
                    >
                        {auth.access?.mode === 'temporary_full_access_pending'
                            ? 'Подтвердить условия'
                            : 'Начать 3 дня бесплатно'}
                    </Link>
                ) : trial || access.phase === 'active' ? (
                    <Link
                        className="button button--secondary button--md access-main-action"
                        href="/dashboard"
                    >
                        Вернуться к работе
                    </Link>
                ) : null}

                {purchase.availability === 'unavailable' ? (
                    <GlassCard className="purchase-unavailable" tone="quiet">
                        <p className="purchase-unavailable__eyebrow">Подписка</p>
                        <h2>Покупка доступа пока не открыта</h2>
                        <p>
                            Условия покупки ещё не утверждены. Сейчас заказ и платёж из
                            приложения недоступны.
                        </p>
                    </GlassCard>
                ) : null}

                <GlassCard className="billing-details" tone="quiet">
                    <h2>Условия и помощь</h2>
                    <p>
                        Если доступ не появился после оплаты в другом канале, сообщите
                        об этом поддержке. Укажите время и способ оплаты, но не
                        отправляйте реквизиты карты.
                    </p>
                    <div className="billing-links">
                        <Link href="/support?topic=payment-access">
                            Оплатил, но доступа нет
                        </Link>
                        <Link href="/support">Другой вопрос</Link>
                        <Link href="/offer">Оферта</Link>
                        <Link href="/privacy">Политика</Link>
                    </div>
                </GlassCard>
            </AppShell>
        </>
    );
}
