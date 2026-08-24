import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import {
    AccessGate,
    Badge,
    BottomSheet,
    Button,
    CheckoutState,
    DataRow,
    GlassCard,
    InlineAlert,
    PlanCard,
    PlanComparison,
    ProgressBar,
    SegmentedControl,
} from '../Components/ui';
import {
    demoAccessStateOptions,
    demoAccessStates,
    type DemoAccessState,
} from '../lib/demoAccess';

type CheckoutDemoState = 'preview' | 'preparing' | 'error' | 'active';

export default function Plans() {
    const [accessState, setAccessState] = useState<DemoAccessState>('preview');
    const [checkoutState, setCheckoutState] = useState<CheckoutDemoState>('preview');
    const [checkoutOpen, setCheckoutOpen] = useState(false);
    const access = demoAccessStates[accessState];

    useEffect(() => {
        if (checkoutState !== 'preparing') {
            return;
        }

        const timeout = window.setTimeout(() => setCheckoutState('error'), 900);
        return () => window.clearTimeout(timeout);
    }, [checkoutState]);

    const openCheckout = (): void => {
        setCheckoutState('preview');
        setCheckoutOpen(true);
    };

    return (
        <>
            <Head title="Доступ" />
            <AppShell
                activeNav="/profile"
                backHref="/profile"
                eyebrow="Планы и доступ"
                title="Ваш следующий шаг"
            >
                <section className="plans-intro page-enter">
                    <Badge tone="accent">Демо-сценарий</Badge>
                    <h2>
                        Меньше ручного
                        <br />
                        <em>поиска.</em>
                    </h2>
                    <p>
                        Сначала — прозрачный trial. Затем вы решаете, нужен ли вам
                        постоянный мониторинг.
                    </p>
                </section>

                <InlineAlert title="Как будет работать оплата" tone="neutral">
                    Когда серверная часть будет готова, счёт откроется в Telegram в
                    Stars. В этом demo invoice не создаётся, а доступ не меняется.
                </InlineAlert>

                <section className="plans-list page-enter page-enter--delay">
                    <PlanCard
                        action={
                            <Button
                                className="plan-card__button"
                                icon="arrow-right"
                                onClick={openCheckout}
                                size="lg"
                            >
                                Открыть demo checkout
                            </Button>
                        }
                        badge={<Badge tone="success">Первый запуск</Badge>}
                        description="Для тех, кому нужен аккуратный поток релевантных закупок без лишнего шума."
                        features={[
                            'Фильтры по теме, региону и бюджету',
                            'Объяснимые причины совпадений',
                            'Уведомления и контроль сроков',
                        ]}
                        featured
                        name="Basic"
                        price="Цена в Stars — скоро"
                    />

                    <PlanCard
                        action={
                            <Button
                                className="plan-card__button"
                                disabled
                                size="lg"
                                variant="secondary"
                            >
                                Следующий этап
                            </Button>
                        }
                        badge="Будущий план"
                        description="Для команд, которым понадобится более глубокая персональная оценка тендеров."
                        features={[
                            'Увеличенные лимиты мониторинга',
                            'Персональный scoring с объяснением факторов',
                            'Анализ ТЗ после проверки качества',
                        ]}
                        name="PRO"
                        price="Появится после Basic"
                    />
                </section>

                <section className="plans-access-preview page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Отдельно от роли</p>
                            <h2>Состояние доступа</h2>
                        </div>
                        <Badge tone="neutral">local demo</Badge>
                    </div>
                    <SegmentedControl
                        label="Примеры состояния доступа"
                        onChange={(value) => setAccessState(value as DemoAccessState)}
                        options={demoAccessStateOptions}
                        value={accessState}
                    />
                    <GlassCard
                        className="access-state-preview"
                        tone={access.tone === 'warning' ? 'quiet' : 'default'}
                    >
                        <div className="access-state-preview__heading">
                            <Badge tone={access.tone}>{access.label}</Badge>
                            <span>{access.detail}</span>
                        </div>
                        <h3>{access.title}</h3>
                        <p>{access.description}</p>
                    </GlassCard>
                </section>

                <section className="plans-comparison page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Прозрачно о доступе</p>
                            <h2>Basic и будущий PRO</h2>
                        </div>
                        <Badge tone="neutral">demo</Badge>
                    </div>
                    <PlanComparison
                        rows={[
                            {
                                feature: 'Фильтры и поиск',
                                basic: 'включены',
                                pro: 'включены',
                            },
                            {
                                feature: 'Мониторинг и уведомления',
                                basic: 'в согласованных лимитах',
                                pro: 'увеличенные лимиты — позже',
                            },
                            {
                                feature: 'Объяснение совпадений',
                                basic: 'детерминированные причины',
                                pro: 'детерминированные причины',
                            },
                            {
                                feature: 'Персональный scoring',
                                basic: 'не входит',
                                pro: 'после quality/cost/privacy gate',
                            },
                            {
                                feature: 'Анализ ТЗ',
                                basic: 'не входит',
                                pro: 'после проверки качества',
                            },
                        ]}
                    />
                    <p className="plans-comparison__note">
                        Цены, лимиты и срок продления будут зафиксированы до запуска
                        Telegram Stars. PRO не обещает рост вероятности победы.
                    </p>
                </section>

                <section className="plans-details page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>До активации</p>
                            <h2>Что уже видно</h2>
                        </div>
                    </div>
                    <div className="plans-data-list">
                        <DataRow
                            detail="Примеры экранов и сценариев"
                            icon="layers"
                            label="Интерактивный preview"
                            value={<Badge tone="success">доступен</Badge>}
                        />
                        <DataRow
                            detail="Запустится один раз после согласий"
                            icon="wave"
                            label="Trial"
                            value="72 часа"
                        />
                        <DataRow
                            detail="После подключения источников"
                            icon="bell"
                            label="Мониторинг"
                            value="готовится"
                        />
                    </div>
                    <ProgressBar
                        detail="demo"
                        label="Готовность персонального контура"
                        value={42}
                    />
                </section>

                {accessState === 'active' ? (
                    <InlineAlert title="Это не настоящий Basic" tone="success">
                        Выбранный пример не создаёт entitlement, запрос, payment или
                        доступ к данным.
                    </InlineAlert>
                ) : (
                    <AccessGate
                        action={
                            <Link
                                className="button button--secondary button--sm"
                                href="/onboarding"
                            >
                                Узнать больше <Icon name="chevron-right" size={16} />
                            </Link>
                        }
                        description="Настройка запроса, trial и реальная оплата появятся после подключения защищённой серверной части."
                        title="Ваш контур ещё настраивается"
                    />
                )}

                <BottomSheet
                    onClose={() => setCheckoutOpen(false)}
                    open={checkoutOpen}
                    title="Checkout · demo"
                >
                    <p className="sheet-description">
                        Образец состояний интерфейса. Здесь не создаётся Telegram Stars
                        invoice и не меняется entitlement.
                    </p>
                    <SegmentedControl
                        label="Состояние demo checkout"
                        onChange={(value) =>
                            setCheckoutState(value as CheckoutDemoState)
                        }
                        options={[
                            { value: 'preview', label: 'Preview' },
                            { value: 'preparing', label: 'Loading' },
                            { value: 'error', label: 'Ошибка' },
                            { value: 'active', label: 'Basic' },
                        ]}
                        value={checkoutState}
                    />
                    <CheckoutStateContent
                        onRetry={() => setCheckoutState('preparing')}
                        state={checkoutState}
                    />
                </BottomSheet>
            </AppShell>
        </>
    );
}

function CheckoutStateContent({
    state,
    onRetry,
}: {
    state: CheckoutDemoState;
    onRetry: () => void;
}) {
    if (state === 'preparing') {
        return (
            <CheckoutState
                description="Пример ожидания ответа перед открытием защищённого checkout. Через мгновение отображается demo-ошибка."
                loading
                title="Готовим checkout-state"
            />
        );
    }

    if (state === 'error') {
        return (
            <CheckoutState
                description="Пример recoverable state. В production причина придёт от сервера, а retry не должен повторно выдать доступ."
                icon="refresh"
                title="Не удалось подготовить checkout"
                tone="danger"
            >
                <Button icon="refresh" onClick={onRetry} variant="secondary">
                    Повторить demo
                </Button>
            </CheckoutState>
        );
    }

    if (state === 'active') {
        return (
            <CheckoutState
                description="Пример состояния после успешной server-side обработки payment event. В этом demo entitlement не создаётся."
                icon="check"
                title="Basic активен · пример"
                tone="success"
            />
        );
    }

    return (
        <CheckoutState
            description="Цена, лимиты и период продления ещё не зафиксированы. Настоящий счёт создаст Laravel после server-side проверки."
            title="Basic ждёт решения"
        >
            <Button icon="arrow-right" onClick={onRetry}>
                Показать loading
            </Button>
        </CheckoutState>
    );
}
