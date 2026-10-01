import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { useTelegramSessionState } from '../lib/telegramSession';
import { InlineAlert } from '../Components/ui';
import type { PageProps } from '../types';

type OnboardingProps = {
    localSubscriberEntryEnabled: boolean;
};

export default function Onboarding({ localSubscriberEntryEnabled }: OnboardingProps) {
    const { auth } = usePage<PageProps>().props;
    const telegramSession = useTelegramSessionState();

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
                    ) : telegramSession === 'checking' ||
                      telegramSession === 'ready' ? (
                        <InlineAlert
                            title="Подтверждаем вход через Telegram"
                            tone="neutral"
                        >
                            Это обычно занимает несколько секунд.
                        </InlineAlert>
                    ) : telegramSession === 'expired' ? (
                        <InlineAlert title="Сессия Telegram устарела" tone="warning">
                            Закройте это окно приложения и снова нажмите Open App в
                            боте. Ваши сохранённые данные останутся на месте.
                        </InlineAlert>
                    ) : telegramSession === 'failed' ? (
                        <>
                            <InlineAlert
                                title="Не удалось подтвердить вход"
                                tone="warning"
                            >
                                Проверьте соединение и повторите попытку. Если ошибка
                                останется, закройте окно приложения и откройте его снова
                                из бота.
                            </InlineAlert>
                            <button
                                className="button button--secondary button--lg onboarding-retry"
                                onClick={() => window.location.reload()}
                                type="button"
                            >
                                Повторить проверку
                            </button>
                        </>
                    ) : (
                        <InlineAlert title="Не получили данные Telegram" tone="warning">
                            Закройте это окно приложения и снова нажмите Open App в
                            боте.
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
