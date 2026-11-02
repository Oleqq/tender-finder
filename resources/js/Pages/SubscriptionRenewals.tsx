import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Button, GlassCard } from '../Components/ui';
import axios from 'axios';

export type Renewal = {
    id: string;
    plan_code: string;
    provider: string;
    amount_minor: number;
    duration_months: number;
    next_charge_at: string;
    state: string;
    notice_status: string;
    notice_sent_at: string | null;
    cancel_url: string;
};

export function RenewalCard({ renewal }: { renewal: Renewal }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [cancelledLocally, setCancelled] = useState(false);
    const cancelled = cancelledLocally || renewal.state === 'cancelled';
    return (
        <GlassCard className="demo-card">
            <h3>
                {renewal.plan_code.toUpperCase()} ·{' '}
                {renewal.provider === 'demo'
                    ? 'Тестовое автопродление'
                    : 'Автопродление'}
            </h3>
            <p>
                {new Intl.NumberFormat('ru-RU', {
                    style: 'currency',
                    currency: 'RUB',
                    maximumFractionDigits: 0,
                }).format(renewal.amount_minor / 100)}{' '}
                каждые {renewal.duration_months} мес.
            </p>
            <p>
                {cancelled
                    ? 'Автопродление отключено. Оплаченный доступ сохранён до конца срока.'
                    : renewal.notice_status === 'failed'
                      ? 'Дата продления будет уточнена после доставки уведомления.'
                      : `Следующее ${renewal.provider === 'demo' ? 'тестовое событие' : 'списание'}: ${new Intl.DateTimeFormat('ru-RU', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Europe/Moscow' }).format(new Date(renewal.next_charge_at))} МСК.`}
            </p>
            {renewal.provider === 'demo' ? (
                <p>Демонстрация без настоящих списаний и отправок в Telegram.</p>
            ) : null}
            {!cancelled &&
                (renewal.notice_status === 'failed' ? (
                    <p>
                        Уведомление ещё не доставлено. Вы можете отменить автопродление.
                    </p>
                ) : renewal.notice_status === 'demo_recorded' ? (
                    <p>Тестовое уведомление записано.</p>
                ) : renewal.notice_sent_at ? (
                    <p>Предварительное уведомление отправлено.</p>
                ) : (
                    <p>
                        Уведомление предусмотрено не позднее чем за 24 часа до списания.
                    </p>
                ))}
            {!cancelled ? (
                <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={async () => {
                        setBusy(true);
                        setError('');
                        try {
                            await axios.post(
                                `/subscriptions/renewals/${renewal.id}/cancel`,
                            );
                            setCancelled(true);
                            router.reload({ only: ['renewals'] });
                        } catch {
                            setError(
                                'Не удалось отменить. Попробуйте ещё раз или обратитесь в поддержку.',
                            );
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    {busy ? 'Отмена…' : 'Отменить автопродление'}
                </Button>
            ) : null}
            {error ? <p role="alert">{error}</p> : null}
        </GlassCard>
    );
}

export default function SubscriptionRenewals({ renewals }: { renewals: Renewal[] }) {
    return (
        <>
            <Head title="Автопродление и отмена" />
            <AppShell title="Автопродление" backHref="/plans" backToPrevious>
                <p>
                    Отмена прекращает будущие продления. Оплаченный период доступа
                    сохраняется.
                </p>
                {renewals.length ? (
                    renewals.map((item) => <RenewalCard key={item.id} renewal={item} />)
                ) : (
                    <GlassCard>
                        <h2>Автопродление не включено</h2>
                        <p>Будущие автоматические списания не запланированы.</p>
                    </GlassCard>
                )}
                <Link href="/support">Поддержка по оплате</Link>
            </AppShell>
        </>
    );
}
