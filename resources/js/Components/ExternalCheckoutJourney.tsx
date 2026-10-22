import { Link } from '@inertiajs/react';
import { Badge, Button, GlassCard, InlineAlert, PlanCard } from './ui';
import type { ExternalCheckout, PurchasePlan } from '../types';

type Props = {
    plans: PurchasePlan[];
    checkout: ExternalCheckout | null;
    onSelectPlan: (code: string) => void;
    onRetry: () => void;
    onCancel: () => void;
    onRefresh: () => void;
};

// For a standalone external sales page only. The Mini App never renders this component.
// All plans, links and checkout statuses must come from trusted server responses.
export function ExternalCheckoutJourney({
    plans,
    checkout,
    onSelectPlan,
    onRetry,
    onCancel,
    onRefresh,
}: Props) {
    if (!checkout) {
        return (
            <section className="external-checkout" aria-label="Выбор плана">
                <h2>Выберите период доступа</h2>
                {plans.length === 0 ? (
                    <InlineAlert title="Условия пока недоступны">
                        Покупка откроется после публикации утверждённых тарифов.
                    </InlineAlert>
                ) : (
                    <div className="external-checkout__plans">
                        {plans.map((plan) => (
                            <div className="external-checkout__plan" key={plan.code}>
                                <PlanCard
                                    name={plan.name}
                                    description={plan.description}
                                    features={[
                                        `Срок: ${plan.duration_days} дн.`,
                                        plan.active_query_limit === null
                                            ? 'Лимит мониторингов указан в условиях'
                                            : `До ${plan.active_query_limit} активных мониторингов`,
                                    ]}
                                    price={new Intl.NumberFormat('ru-RU', {
                                        style: 'currency',
                                        currency: plan.currency,
                                    }).format(plan.amount_minor / 100)}
                                    action={
                                        <Button onClick={() => onSelectPlan(plan.code)}>
                                            Выбрать план
                                        </Button>
                                    }
                                />
                                <a href={plan.terms_url}>Условия плана</a>
                            </div>
                        ))}
                    </div>
                )}
            </section>
        );
    }

    const orderLabel = `Заказ ${checkout.order_id}`;
    if (checkout.state === 'succeeded') {
        return (
            <GlassCard className="external-checkout" tone="accent">
                <Badge tone="success">Доступ подтверждён</Badge>
                <h2>Оплата подтверждена</h2>
                <p>{orderLabel}. Рабочее пространство доступно.</p>
                <p>План: {checkout.plan_name}</p>
                <p>
                    Срок доступа:{' '}
                    {checkout.access.ends_at
                        ? new Intl.DateTimeFormat('ru-RU', {
                              dateStyle: 'long',
                          }).format(new Date(checkout.access.ends_at))
                        : 'без даты окончания'}
                </p>
                <p>
                    Лимит мониторингов:{' '}
                    {checkout.access.active_query_limit ?? 'не указан'}
                </p>
                <Link className="button button--primary button--md" href="/dashboard">
                    Вернуться к работе
                </Link>
            </GlassCard>
        );
    }

    if (checkout.state === 'access_pending') {
        return (
            <GlassCard className="external-checkout">
                <Badge tone="warning">Требуется проверка</Badge>
                <h2>Платёж найден, доступ ещё не появился</h2>
                <p>
                    {orderLabel}. {checkout.message}
                </p>
                <div className="external-checkout__actions">
                    <Button onClick={onRefresh} variant="secondary">
                        Проверить доступ
                    </Button>
                    <Link href="/support?topic=payment-access">
                        Обратиться в поддержку
                    </Link>
                </div>
            </GlassCard>
        );
    }

    if (checkout.state === 'preparing') {
        return (
            <GlassCard className="external-checkout" tone="quiet">
                <Badge>Подготовка заказа</Badge>
                <h2>Создаём заказ</h2>
                <p>{orderLabel}. Подождите, пока сервер подготовит следующий шаг.</p>
                <div className="external-checkout__actions">
                    <Button onClick={onRefresh} variant="secondary">
                        Обновить состояние
                    </Button>
                    <Button onClick={onCancel} variant="ghost">
                        Отменить
                    </Button>
                </div>
            </GlassCard>
        );
    }

    if (checkout.state === 'awaiting_payment') {
        return (
            <GlassCard className="external-checkout" tone="quiet">
                <Badge tone="warning">Ожидание оплаты</Badge>
                <h2>Ожидаем подтверждение платежа</h2>
                <p>
                    {orderLabel}. Переход обратно на сайт не подтверждает оплату; статус
                    проверяется сервером.
                </p>
                <div className="external-checkout__actions">
                    <a
                        className="button button--primary button--md"
                        href={checkout.confirmation_url}
                    >
                        Продолжить оплату
                    </a>
                    <Button onClick={onRefresh} variant="secondary">
                        Проверить статус
                    </Button>
                    <Button onClick={onCancel} variant="ghost">
                        Отменить
                    </Button>
                </div>
            </GlassCard>
        );
    }

    return (
        <GlassCard className="external-checkout" tone="quiet">
            <Badge tone={checkout.state === 'failed' ? 'danger' : 'neutral'}>
                {checkout.state === 'failed' ? 'Ошибка заказа' : 'Заказ отменён'}
            </Badge>
            <h2>
                {checkout.state === 'failed'
                    ? 'Не удалось продолжить оплату'
                    : 'Покупка отменена'}
            </h2>
            <p>
                {orderLabel}. {checkout.message}
            </p>
            <div className="external-checkout__actions">
                <Button onClick={onRetry}>Попробовать снова</Button>
                <Link href="/support?topic=payment-access">Нужна помощь</Link>
            </div>
        </GlassCard>
    );
}
