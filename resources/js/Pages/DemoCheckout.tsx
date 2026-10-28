import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useRef, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, GlassCard, InlineAlert } from '../Components/ui';
import { useAccessPresentation } from '../lib/useAccessPresentation';
import type { PageProps, PurchasePlan } from '../types';

type Order = {
    id: string;
    plan_code: string;
    duration_months: number;
    amount_minor: number;
    status: 'pending' | 'succeeded' | 'canceled' | 'failed';
    ends_at: string;
    expires_at: string;
    terms: PurchasePlan;
};
type Props = {
    offers: PurchasePlan[];
    order: Order | null;
    previewEnds: Record<number, string>;
};
const date = (value: string) =>
    new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: 'Europe/Moscow',
    }).format(new Date(value));
const money = (value: number) =>
    new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: 0,
    }).format(value / 100);

export default function DemoCheckout() {
    const {
        auth,
        offers,
        order: serverOrder,
        previewEnds,
    } = usePage<PageProps<Props>>().props;
    const access = useAccessPresentation(auth.access);
    const [order, setOrder] = useState(serverOrder);
    const [months, setMonths] = useState(serverOrder?.duration_months ?? 1);
    const [plan, setPlan] = useState(serverOrder?.plan_code ?? 'basic');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const key = useRef(crypto.randomUUID());
    const locked = useRef(false);
    const offer = offers.find(
        (item) => item.code === plan && item.duration_months === months,
    )!;
    const active = auth.access?.state === 'trialing' || auth.access?.state === 'active';
    const canSelect =
        !active &&
        auth.access?.state === 'expired' &&
        (!order ||
            order.status === 'failed' ||
            order.status === 'canceled' ||
            (order.status === 'succeeded' &&
                new Date(order.ends_at).getTime() <= Date.now()));

    async function send(
        path: string,
        data: Record<string, unknown> = {},
        method: 'post' | 'get' = 'post',
    ) {
        if (locked.current) return;
        locked.current = true;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const response =
                method === 'get' ? await axios.get(path) : await axios.post(path, data);
            if (response.data.order) {
                setOrder(response.data.order);
                if (
                    ['failed', 'canceled', 'succeeded'].includes(
                        response.data.order.status,
                    )
                )
                    key.current = crypto.randomUUID();
            }
            if (path.endsWith('/expire')) {
                setOrder(null);
                key.current = crypto.randomUUID();
            }
            if (data.outcome === 'pending')
                setNotice('Платёж ожидает подтверждения. Доступ пока не выдан.');
            if (method === 'get') setNotice('Статус проверен на сервере.');
            router.reload({ only: ['auth', 'previewEnds'] });
        } catch (reason) {
            setError(
                axios.isAxiosError(reason)
                    ? (reason.response?.data?.message ??
                          'Нет связи с сервером. Выбор сохранён — повторите запрос.')
                    : 'Не удалось выполнить запрос. Попробуйте ещё раз.',
            );
        } finally {
            locked.current = false;
            setBusy(false);
        }
    }

    return (
        <>
            <Head title="Демонстрация подписки" />
            <AppShell
                title="Подписка · демо"
                eyebrow="Внешний веб-сценарий"
                navigationVisible={!!auth.user}
            >
                <InlineAlert title="Тестовая оплата · без списаний">
                    Это изолированная демонстрация. Деньги не списываются, банковская
                    карта не нужна. Автопродления нет.
                </InlineAlert>
                {!auth.user ? (
                    <GlassCard className="demo-card">
                        <h2>От первого входа до подписки</h2>
                        <p>
                            Начните 3 дня бесплатно, затем выберите тариф и проверьте
                            тестовую оплату. Аккаунт хранится в cookie этого браузера;
                            после их удаления восстановить его нельзя.
                        </p>
                        <Button
                            onClick={() => router.post('/demo/session')}
                            disabled={busy}
                        >
                            Начать демонстрацию
                        </Button>
                    </GlassCard>
                ) : (
                    <>
                        <GlassCard className="demo-card">
                            <Badge tone={access.tone}>{access.badge}</Badge>
                            <h2>{access.title}</h2>
                            <p>{access.detail}</p>
                            {auth.access?.state === 'preview' ? (
                                <Link
                                    className="button button--primary button--md"
                                    href="/consents"
                                >
                                    Принять документы и начать 72 часа
                                </Link>
                            ) : null}
                            {active ? (
                                <>
                                    <p>
                                        Лимит: {auth.access?.active_query_limit}{' '}
                                        активных мониторингов.
                                    </p>
                                    <Link
                                        className="button button--primary button--md"
                                        href="/queries"
                                    >
                                        Продолжить работу
                                    </Link>
                                    <p>
                                        Замороженные мониторинги включаются по одному в
                                        пределах тарифа. Приостановленные вами остаются
                                        на паузе; старые уведомления не рассылаются.
                                    </p>
                                    <Button
                                        variant="ghost"
                                        disabled={busy}
                                        onClick={() => void send('/demo/expire')}
                                    >
                                        Завершить период в этом демо-аккаунте
                                    </Button>
                                </>
                            ) : null}
                            {auth.access?.state === 'expired' ? (
                                <p>
                                    Период завершён. Платные действия остановлены,
                                    материалы сохранены. Выберите подписку, чтобы
                                    продолжить.
                                </p>
                            ) : null}
                        </GlassCard>
                        {canSelect ? (
                            <section className="demo-card" aria-label="Тариф и период">
                                <h2>Выберите подписку</h2>
                                <div
                                    className="demo-periods"
                                    role="group"
                                    aria-label="Период доступа"
                                >
                                    {[1, 3, 12].map((value) => (
                                        <Button
                                            key={value}
                                            disabled={busy}
                                            variant={
                                                months === value
                                                    ? 'primary'
                                                    : 'secondary'
                                            }
                                            aria-pressed={months === value}
                                            onClick={() => {
                                                setMonths(value);
                                                key.current = crypto.randomUUID();
                                            }}
                                        >
                                            {value} мес.
                                        </Button>
                                    ))}
                                </div>
                                <div className="demo-plans">
                                    {offers
                                        .filter(
                                            (item) => item.duration_months === months,
                                        )
                                        .map((item) => (
                                            <button
                                                key={item.code}
                                                type="button"
                                                className={`demo-plan ${plan === item.code ? 'demo-plan--selected' : ''}`}
                                                aria-pressed={plan === item.code}
                                                disabled={busy}
                                                onClick={() => {
                                                    setPlan(item.code);
                                                    key.current = crypto.randomUUID();
                                                }}
                                            >
                                                <strong>{item.name}</strong>
                                                <span>{money(item.amount_minor)}</span>
                                                <span>
                                                    До {item.active_query_limit}{' '}
                                                    мониторингов
                                                </span>
                                                <span>{item.description}</span>
                                            </button>
                                        ))}
                                </div>
                                <GlassCard className="demo-card">
                                    <h3>
                                        {offer.name} · {months} мес.
                                    </h3>
                                    <p>
                                        Итого за весь период:{' '}
                                        <strong>{money(offer.amount_minor)}</strong>
                                    </p>
                                    <p>
                                        До {date(previewEnds[months])} МСК. Точная дата
                                        будет зафиксирована в заказе перед
                                        подтверждением.
                                    </p>
                                    <p>
                                        Разовая покупка, без автоматического продления.
                                    </p>
                                    <Button
                                        disabled={busy}
                                        onClick={() =>
                                            void send('/demo/orders', {
                                                plan_code: plan,
                                                duration_months: months,
                                                idempotency_key: key.current,
                                            })
                                        }
                                    >
                                        {busy
                                            ? 'Подготовка…'
                                            : 'Перейти к тестовой оплате'}
                                    </Button>
                                </GlassCard>
                            </section>
                        ) : null}
                        {order ? (
                            <GlassCard
                                className="demo-card"
                                tone={order.status === 'succeeded' ? 'accent' : 'quiet'}
                            >
                                <Badge
                                    tone={
                                        order.status === 'succeeded'
                                            ? 'success'
                                            : 'warning'
                                    }
                                >
                                    {
                                        {
                                            pending: 'Ожидание',
                                            succeeded:
                                                new Date(order.ends_at).getTime() >
                                                Date.now()
                                                    ? 'Демо-доступ выдан'
                                                    : 'Период заказа завершён',
                                            failed: 'Тестовая ошибка',
                                            canceled: 'Заказ отменён',
                                        }[order.status]
                                    }
                                </Badge>
                                <h2>
                                    {order.terms.name} · {order.duration_months} мес. ·{' '}
                                    {money(order.amount_minor)}
                                </h2>
                                <p>
                                    Доступ до {date(order.ends_at)} МСК. До{' '}
                                    {order.terms.active_query_limit} активных
                                    мониторингов. Без автопродления.
                                </p>
                                <p className="demo-order-id">Заказ {order.id}</p>
                                {order.status === 'pending' ? (
                                    <>
                                        <p>
                                            Выберите тестовый исход. Заказ действует до{' '}
                                            {date(order.expires_at)} МСК; возврат на эту
                                            страницу сам по себе не выдаёт доступ.
                                        </p>
                                        <div className="demo-actions">
                                            {(
                                                [
                                                    [
                                                        'succeeded',
                                                        'Подтвердить тестовую оплату',
                                                    ],
                                                    ['pending', 'Оставить в ожидании'],
                                                    ['failed', 'Смоделировать ошибку'],
                                                    ['canceled', 'Отменить заказ'],
                                                ] as const
                                            ).map(([outcome, label]) => (
                                                <Button
                                                    key={outcome}
                                                    disabled={busy}
                                                    variant={
                                                        outcome === 'succeeded'
                                                            ? 'primary'
                                                            : 'secondary'
                                                    }
                                                    onClick={() =>
                                                        void send(
                                                            `/demo/orders/${order.id}/confirm`,
                                                            { outcome },
                                                        )
                                                    }
                                                >
                                                    {label}
                                                </Button>
                                            ))}
                                        </div>
                                        <Button
                                            variant="ghost"
                                            disabled={busy}
                                            onClick={() =>
                                                void send(
                                                    `/demo/orders/${order.id}`,
                                                    {},
                                                    'get',
                                                )
                                            }
                                        >
                                            Проверить статус
                                        </Button>
                                    </>
                                ) : null}
                                {order.status === 'failed' ||
                                order.status === 'canceled' ? (
                                    <p>
                                        Деньги не списаны. Выбор сохранён выше: можно
                                        повторить с новым заказом.
                                    </p>
                                ) : null}
                            </GlassCard>
                        ) : null}
                    </>
                )}
                {busy ? <p role="status">Запрос обрабатывается…</p> : null}
                {notice ? <p role="status">{notice}</p> : null}
                {error ? (
                    <div role="alert">
                        <InlineAlert tone="danger" title="Не удалось продолжить">
                            {error}
                        </InlineAlert>
                    </div>
                ) : null}
                <div className="billing-links">
                    <a href="/pricing">Тарифы и цены</a>
                    <Link href="/offer">Условия</Link>
                    <Link href="/privacy">Политика</Link>
                    {auth.user ? <Link href="/support">Поддержка</Link> : null}
                </div>
            </AppShell>
        </>
    );
}
