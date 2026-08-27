import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard } from '../Components/ui';
import type { PageProps } from '../types';

export default function Welcome() {
    const { auth, localMvpOperatorAvailable, localMvpSubscriberAvailable } = usePage<
        PageProps<{
            localMvpOperatorAvailable: boolean;
            localMvpSubscriberAvailable: boolean;
        }>
    >().props;

    return (
        <>
            <Head title="Tender Finder" />
            <AppShell
                navigationVisible={false}
                title="Tender Finder"
                eyebrow="Ваш радар закупок"
            >
                <section className="welcome-hero page-enter">
                    <div aria-hidden="true" className="welcome-hero__signal">
                        <span className="signal-ring signal-ring--one" />
                        <span className="signal-ring signal-ring--two" />
                        <span className="signal-core">
                            <Icon name="wave" size={35} />
                        </span>
                    </div>
                    <Badge tone="accent">Mini App · beta</Badge>
                    <h2>
                        Находите тендеры,
                        <br />
                        <em>а не шум.</em>
                    </h2>
                    <p>
                        Соберём важные закупки в один спокойный поток — с понятными
                        сигналами, сроками и контекстом.
                    </p>
                </section>

                <GlassCard
                    className="welcome-promise page-enter page-enter--delay"
                    tone="accent"
                >
                    <div className="welcome-promise__icon">
                        <Icon name="spark" size={20} />
                    </div>
                    <div>
                        <p>Сфокусированный поиск</p>
                        <strong>Релевантность — прежде количества</strong>
                    </div>
                </GlassCard>

                <div className="welcome-actions page-enter page-enter--later">
                    {auth.user?.role === 'super_admin' ? (
                        <>
                            <Badge tone="success">Вы вошли как super_admin</Badge>
                            <Link
                                className="button button--primary button--lg"
                                href="/mvp/workspace"
                            >
                                <span>Открыть ЕИС-рабочее место</span>
                                <Icon name="arrow-right" size={20} />
                            </Link>
                        </>
                    ) : localMvpOperatorAvailable ? (
                        <>
                            <Link
                                className="button button--primary button--lg"
                                href="/local/mvp-operator"
                            >
                                <span>Войти как оператор</span>
                                <Icon name="arrow-right" size={20} />
                            </Link>
                            {localMvpSubscriberAvailable ? (
                                <Link
                                    className="text-link"
                                    href="/local/mvp-subscriber"
                                >
                                    Проверить вход subscriber{' '}
                                    <Icon name="chevron-right" size={17} />
                                </Link>
                            ) : null}
                        </>
                    ) : (
                        <Link
                            className="button button--primary button--lg"
                            href="/onboarding"
                        >
                            <span>Начать знакомство</span>
                            <Icon name="arrow-right" size={20} />
                        </Link>
                    )}
                    {auth.user?.role === 'super_admin' ? null : (
                        <Link className="text-link" href="/dashboard">
                            Открыть демо-обзор <Icon name="chevron-right" size={17} />
                        </Link>
                    )}
                </div>
                <p className="welcome-footnote">
                    {localMvpOperatorAvailable
                        ? 'Local MVP: роли доступны только в Docker-контуре и не принимают Telegram ID из браузера.'
                        : 'Работает в Telegram и в обычном браузере.'}
                </p>
            </AppShell>
        </>
    );
}
