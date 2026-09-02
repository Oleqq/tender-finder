import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { GlassCard, InlineAlert } from '../Components/ui';
import type { PageProps } from '../types';

type OnboardingProps = {
    localSubscriberEntryEnabled: boolean;
};

const values = [
    {
        icon: 'compass' as const,
        title: 'Свой фокус',
        text: 'Следите только за теми направлениями и регионами, которые важны вашей команде.',
    },
    {
        icon: 'bell' as const,
        title: 'Точные сигналы',
        text: 'Получайте понятные уведомления, когда появляется подходящая закупка.',
    },
    {
        icon: 'chart' as const,
        title: 'Видимый ритм',
        text: 'Держите в поле зрения работу мониторинга и динамику новых возможностей.',
    },
];

export default function Onboarding({ localSubscriberEntryEnabled }: OnboardingProps) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Как это работает" />
            <AppShell
                backHref="/"
                navigationVisible={false}
                title="Как это работает"
                eyebrow="01 / 02"
            >
                <section className="onboarding-intro page-enter">
                    <span className="onboarding-intro__eyebrow">Tender Finder</span>
                    <h2>
                        Ваши тендеры
                        <br />в фокусе.
                    </h2>
                    <p>
                        Сервис будет бережно собирать и показывать перспективные
                        возможности — без перегруженных таблиц.
                    </p>
                </section>
                <div className="value-list page-enter page-enter--delay">
                    {values.map((value, index) => (
                        <GlassCard
                            as="article"
                            className="value-card"
                            key={value.title}
                            tone={index === 0 ? 'accent' : 'default'}
                        >
                            <span className="value-card__icon">
                                <Icon name={value.icon} size={21} />
                            </span>
                            <div>
                                <h3>{value.title}</h3>
                                <p>{value.text}</p>
                            </div>
                        </GlassCard>
                    ))}
                </div>
                <div className="onboarding-actions page-enter page-enter--later">
                    {auth.user ? (
                        <Link
                            className="button button--primary button--lg"
                            href="/consents"
                        >
                            <span>Продолжить</span>
                            <Icon name="arrow-right" size={20} />
                        </Link>
                    ) : localSubscriberEntryEnabled ? (
                        <Link
                            className="button button--primary button--lg"
                            href="/local/mvp-subscriber"
                        >
                            <span>Продолжить локально</span>
                            <Icon name="arrow-right" size={20} />
                        </Link>
                    ) : (
                        <InlineAlert
                            title="Откройте приложение из Telegram"
                            tone="neutral"
                        >
                            Перед стартом сервер должен подтвердить вашу
                            Telegram-сессию. После этого кнопка продолжения появится
                            автоматически.
                        </InlineAlert>
                    )}
                    <div aria-label="Шаг 1 из 2" className="step-dots">
                        <span className="is-active" />
                        <span />
                    </div>
                </div>
            </AppShell>
        </>
    );
}
