import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import {
    AccessGate,
    Badge,
    Button,
    DataRow,
    InlineAlert,
    PlanCard,
    PlanComparison,
    ProgressBar,
    Toast,
} from '../Components/ui';

export default function Plans() {
    const [toastVisible, setToastVisible] = useState(false);

    useEffect(() => {
        if (!toastVisible) {
            return;
        }

        const timeout = window.setTimeout(() => setToastVisible(false), 2600);
        return () => window.clearTimeout(timeout);
    }, [toastVisible]);

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
                    Stars. В этом demo платежи и доступ не меняются.
                </InlineAlert>

                <section className="plans-list page-enter page-enter--delay">
                    <PlanCard
                        action={
                            <Button
                                className="plan-card__button"
                                icon="arrow-right"
                                onClick={() => setToastVisible(true)}
                                size="lg"
                            >
                                Посмотреть путь оплаты
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

                <Toast
                    message="В production здесь откроется Telegram Stars invoice"
                    tone="neutral"
                    visible={toastVisible}
                />
            </AppShell>
        </>
    );
}
