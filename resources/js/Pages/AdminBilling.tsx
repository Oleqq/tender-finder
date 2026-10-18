import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, GlassCard, InlineAlert } from '../Components/ui';
import type { PageProps } from '../types';

type AdminPayment = {
    id: number;
    user: string | null;
    plan: string | null;
    status: string;
    amount: number;
    created_at: string | null;
    paid_at: string | null;
    refundable: boolean;
};

export default function AdminBilling() {
    const { payments } = usePage<PageProps<{ payments: AdminPayment[] }>>().props;
    const refund = useForm({ reason: '' });

    const issueRefund = (payment: AdminPayment): void => {
        const reason = window.prompt('Причина возврата для аудита (не менее 10 символов):');
        if (!reason || reason.trim().length < 10) return;
        refund.transform(() => ({ reason: reason.trim() })).post(`/billing/admin/payments/${payment.id}/refund`, {
            preserveScroll: true,
            onFinish: () => refund.reset(),
        });
    };

    return (
        <>
            <Head title="Платежи · Администратор" />
            <AppShell activeNav="/profile" backHref="/plans" eyebrow="Управление" title="Платежи">
                <InlineAlert title="Возвраты Telegram Stars" tone="neutral">
                    Перед возвратом проверьте обращение пользователя и платёж. Действие записывается в аудит.
                </InlineAlert>
                {refund.errors.billing && <InlineAlert title="Проверьте статус" tone="warning">{refund.errors.billing}</InlineAlert>}
                {payments.length === 0 && <GlassCard tone="quiet"><p>Платежей пока нет.</p></GlassCard>}
                <section className="billing-history" aria-label="Последние платежи">
                    {payments.map((payment) => (
                        <GlassCard key={payment.id} className="billing-payment" tone="quiet">
                            <div><strong>{payment.user ?? 'Пользователь'} · {payment.plan ?? 'Тариф'}</strong>
                                <Badge tone={payment.status === 'paid' ? 'success' : 'neutral'}>{payment.status}</Badge></div>
                            <span>{payment.amount} Stars · {payment.paid_at ? new Date(payment.paid_at).toLocaleString('ru-RU') : 'не оплачен'}</span>
                            {payment.refundable && <Button variant="secondary" size="sm" disabled={refund.processing} onClick={() => issueRefund(payment)}>Оформить возврат</Button>}
                        </GlassCard>
                    ))}
                </section>
                <Link href="/support/admin">Обращения пользователей</Link>
            </AppShell>
        </>
    );
}
