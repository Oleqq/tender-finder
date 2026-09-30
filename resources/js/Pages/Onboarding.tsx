import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { InlineAlert } from '../Components/ui';
import type { PageProps } from '../types';

type OnboardingProps = {
    localSubscriberEntryEnabled: boolean;
};

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
                    <h2>Найдите подходящие закупки</h2>
                    <p>
                        Настройте поиск под свою работу. Подходящие карточки появятся в
                        ленте вместе с причинами совпадения.
                    </p>
                </section>
                <div className="onboarding-actions page-enter page-enter--delay">
                    {auth.user ? (
                        <Link
                            className="button button--primary button--lg"
                            href="/consents"
                        >
                            <span>Начать настройку</span>
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
