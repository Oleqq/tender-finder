import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, Button, GlassCard, InlineAlert } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import { useTelegramWebApp } from '../lib/telegram';
import type { PageProps } from '../types';

type PaymentItem = {
    id: number;
    plan: string | null;
    status: 'pending' | 'paid' | 'failed' | 'refunded';
    amount: number;
    paid_at: string | null;
    period_ends_at: string | null;
};

type BillingState = {
    enabled: boolean;
    consents_current: boolean;
    prices: { basic: number; pro: number };
    period_seconds: number;
    subscription: {
        id: number;
        plan: string | null;
        status: string;
        ends_at: string | null;
        auto_renew: boolean;
        renewal_failed: boolean;
    } | null;
    payments: PaymentItem[];
};

type PlansProps = PageProps<{ billing: BillingState }>;

const dateLabel = (date: string | null): string =>
    date ? new Date(date).toLocaleString('ru-RU', { dateStyle: 'medium' }) : '—';

const paymentLabel: Record<PaymentItem['status'], string> = {
    pending: 'Ожидает оплаты',
    paid: 'Оплачено',
    failed: 'Не завершено',
    refunded: 'Возвращено',
};

export default function Plans() {
    const { auth, billing: initialBilling } = usePage<PlansProps>().props;
    const [billing, setBilling] = useState(initialBilling);
    const [busy, setBusy] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const [polling, setPolling] = useState(false);
    const webApp = useTelegramWebApp();
    const access = presentAccess(auth.access);
    const isSuperAdmin = auth.user?.role === 'super_admin';
    const hasPermanentAccess = auth.access?.state === 'active' && auth.access.ends_at === null;
    const activeSubscription =
        billing.subscription?.status === 'active' &&
        billing.subscription.ends_at !== null &&
        new Date(billing.subscription.ends_at).getTime() > Date.now();

    useEffect(() => setBilling(initialBilling), [initialBilling]);

    useEffect(() => {
        if (!polling) return;
        let attempts = 0;
        const timer = window.setInterval(async () => {
            attempts += 1;
            try {
                const response = await axios.get<{ billing: BillingState }>('/billing/status');
                setBilling(response.data.billing);
                if (response.data.billing.payments[0]?.status === 'paid') {
                    setPolling(false);
                    setNotice('Telegram подтвердил платёж. Доступ обновлён.');
                    router.reload({ only: ['auth', 'billing'] });
                }
            } catch {
                // A temporary network error does not mean that the payment failed.
            }
            if (attempts >= 15) {
                setPolling(false);
                setNotice('Подтверждение ещё не пришло. Проверьте статус позже или напишите в поддержку.');
            }
        }, 2000);
        return () => window.clearInterval(timer);
    }, [polling]);

    const buy = async (plan: 'basic' | 'pro'): Promise<void> => {
        if (!webApp?.openInvoice) {
            setNotice('Откройте Tender Finder через Telegram, чтобы оплатить подписку.');
            return;
        }
        setBusy(plan);
        setNotice('');
        try {
            const response = await axios.post<{ invoice_url: string }>('/billing/invoice', { plan });
            webApp.openInvoice(response.data.invoice_url, (status) => {
                setBusy(null);
                if (status === 'cancelled') {
                    setNotice('Оплата отменена. Доступ не изменился.');
                } else if (status === 'failed') {
                    setNotice('Оплата не прошла. Попробуйте ещё раз или напишите в поддержку.');
                } else {
                    setNotice('Проверяем подтверждение Telegram. Доступ появится после сообщения об оплате.');
                    setPolling(true);
                }
            });
        } catch (error) {
            const message = axios.isAxiosError(error) ? error.response?.data?.message : null;
            setNotice(typeof message === 'string' ? message : 'Не удалось создать счёт. Попробуйте позже.');
            setBusy(null);
        }
    };

    const changeRenewal = (cancel: boolean): void => {
        if (!billing.subscription) return;
        setBusy('renewal');
        setNotice('');
        router.post(`/billing/subscriptions/${billing.subscription.id}/${cancel ? 'cancel' : 'resume'}`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                setNotice(cancel ? 'Продление остановлено. Оплаченный период остаётся.' : 'Продление включено.');
                router.reload({ only: ['billing'] });
            },
            onError: (errors) => setNotice(errors.billing ?? 'Не удалось изменить подписку.'),
            onFinish: () => setBusy(null),
        });
    };

    return (
        <>
            <Head title="Подписка и доступ" />
            <AppShell activeNav="/profile" backHref="/profile" eyebrow="Аккаунт" title="Подписка">
                <section className="plans-intro page-enter">
                    <Badge tone={access.tone}>{access.badge}</Badge>
                    <h2>{access.title}</h2>
                    <p>{access.description}</p>
                </section>

                <GlassCard className="access-state-card page-enter page-enter--delay" tone="accent">
                    <div><span className="access-state-card__icon"><Icon name="shield" size={20} /></span><p>Текущий доступ</p></div>
                    <strong>{access.badge}</strong>
                    <span>{access.detail}</span>
                </GlassCard>

                {notice && <InlineAlert title="Статус оплаты" tone="neutral">{notice}</InlineAlert>}
                {!billing.enabled && (
                    <InlineAlert title="Оплата пока не подключена" tone="neutral">
                        Тарифы и платежи Telegram Stars появятся после завершения закрытой проверки.
                    </InlineAlert>
                )}
                {billing.enabled && hasPermanentAccess && (
                    <InlineAlert title="У вас бессрочный доступ" tone="neutral">
                        Подписка не нужна. Платёжные тарифы доступны для пользователей без бессрочного доступа.
                    </InlineAlert>
                )}

                {billing.subscription && (
                    <GlassCard className="billing-current" tone="accent">
                        <Badge tone={activeSubscription ? 'success' : 'neutral'}>
                            {activeSubscription ? 'Оплаченный доступ' : 'Период завершён'}
                        </Badge>
                        <h2>{billing.subscription.plan ?? 'Подписка'}</h2>
                        <p>Доступ до {dateLabel(billing.subscription.ends_at)}</p>
                        <p>{billing.subscription.auto_renew ? 'Продление включено' : 'Продление остановлено'}</p>
                        {billing.subscription.renewal_failed && <p>Последнее продление не прошло. Проверьте баланс Stars.</p>}
                        {activeSubscription && (
                            <Button variant="secondary" disabled={busy !== null} onClick={() => changeRenewal(billing.subscription?.auto_renew ?? false)}>
                                {billing.subscription.auto_renew ? 'Остановить продление' : 'Возобновить продление'}
                            </Button>
                        )}
                    </GlassCard>
                )}

                {billing.enabled && (
                    <section className="plans-list" aria-label="Тарифы">
                        {(['basic', 'pro'] as const).map((plan) => (
                            <GlassCard key={plan} className="plan-card" tone={plan === 'pro' ? 'accent' : 'default'}>
                                <div className="plan-card__heading">
                                    <div><p>Каждые 30 дней</p><h2>{plan === 'basic' ? 'Basic' : 'Про'}</h2></div>
                                    <strong>{billing.prices[plan]} Stars</strong>
                                </div>
                                <p className="plan-card__description">
                                    Подписка продлевается автоматически каждые 30 дней. Отменить продление можно здесь в любой момент.
                                </p>
                                <div className="plan-card__action">
                                    <Button className="plan-card__button" disabled={busy !== null || polling || Boolean(activeSubscription) || hasPermanentAccess || !billing.consents_current} onClick={() => buy(plan)}>
                                        {busy === plan ? 'Открываем счёт…' : polling ? 'Ожидаем подтверждение…' : `Оплатить ${billing.prices[plan]} Stars`}
                                    </Button>
                                </div>
                            </GlassCard>
                        ))}
                    </section>
                )}

                {billing.enabled && !billing.consents_current && (
                    <InlineAlert title="Перед оплатой" tone="neutral">
                        Примите актуальные условия и политику на <Link href="/consents">экране согласий</Link>.
                    </InlineAlert>
                )}

                <GlassCard className="billing-details" tone="quiet">
                    <h2>Условия и помощь</h2>
                    <p>{billing.enabled ? 'Списание проходит в Telegram Stars. Доступ открывается после подтверждения Telegram.' : 'Приём платежей пока не открыт.'}</p>
                    <div className="billing-links">
                        {billing.enabled && <Link href="/offer">Оферта</Link>}
                        {billing.enabled && <Link href="/privacy">Политика</Link>}
                        <Link href="/support">Вопрос по оплате или возврату</Link>
                    </div>
                </GlassCard>

                {billing.payments.length > 0 && (
                    <section className="billing-history">
                        <h2>История платежей</h2>
                        {billing.payments.map((payment) => (
                            <GlassCard key={payment.id} className="billing-payment" tone="quiet">
                                <div><strong>{payment.plan ?? 'Подписка'}</strong><Badge tone={payment.status === 'paid' ? 'success' : 'neutral'}>{paymentLabel[payment.status]}</Badge></div>
                                <span>{payment.amount} Stars · {dateLabel(payment.paid_at)}</span>
                            </GlassCard>
                        ))}
                    </section>
                )}

                {isSuperAdmin && (
                    <GlassCard className="access-admin page-enter page-enter--later" tone="quiet">
                        <div><p>Инструменты владельца</p><h2>Управление</h2></div>
                        <div><Link href="/operations">Аналитика <Icon name="chevron-right" size={16} /></Link>
                            <Link href="/billing/admin">Платежи <Icon name="chevron-right" size={16} /></Link></div>
                    </GlassCard>
                )}
            </AppShell>
        </>
    );
}
