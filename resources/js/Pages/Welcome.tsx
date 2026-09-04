import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard } from '../Components/ui';
import type { PageProps } from '../types';

export default function Welcome() {
    const { auth } = usePage<PageProps>().props;
    const isSuperAdmin = auth.user?.role === 'super_admin';
    const needsFirstStart = auth.user !== null && auth.access?.state === 'preview';

    return (
        <>
            <Head title="Tender Finder" />
            <AppShell navigationVisible={Boolean(auth.user)} title="Tender Finder">
                <section className="welcome-hero page-enter">
                    <div className="welcome-hero__signal" aria-hidden="true">
                        <span className="signal-ring signal-ring--one" />
                        <span className="signal-ring signal-ring--two" />
                        <span className="signal-core">
                            <Icon name="wave" size={28} />
                        </span>
                    </div>
                    <Badge tone="accent">Рабочий поиск закупок</Badge>
                    <h2>
                        Тендеры,
                        <br />
                        которые <em>важны.</em>
                    </h2>
                    <p>
                        Сохраняйте свои условия и разбирайте совпадения с понятной
                        причиной попадания в поток.
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
                        <p>Сфокусированный поток</p>
                        <strong>
                            Только серверные совпадения по вашим мониторингам
                        </strong>
                    </div>
                </GlassCard>

                <div className="welcome-actions page-enter page-enter--later">
                    {auth.user ? (
                        <>
                            <Badge tone={needsFirstStart ? 'accent' : isSuperAdmin ? 'success' : 'accent'}>
                                {needsFirstStart
                                    ? 'Первый запуск'
                                    : isSuperAdmin
                                      ? 'Расширенный доступ'
                                      : 'Ваше рабочее пространство'}
                            </Badge>
                            <Link
                                className="button button--primary button--lg"
                                href={needsFirstStart ? '/onboarding' : '/dashboard'}
                            >
                                <span>
                                    {needsFirstStart
                                        ? 'Начать 3 дня бесплатно'
                                        : 'Открыть рабочее пространство'}
                                </span>
                                <Icon name="arrow-right" size={20} />
                            </Link>
                            {isSuperAdmin && !needsFirstStart ? (
                                <div className="welcome-admin-links">
                                    <Link href="/mvp/workspace">
                                        Поиск ЕИС{' '}
                                        <Icon name="chevron-right" size={17} />
                                    </Link>
                                    <Link href="/operations">
                                        Аналитика{' '}
                                        <Icon name="chevron-right" size={17} />
                                    </Link>
                                </div>
                            ) : null}
                        </>
                    ) : (
                        <>
                            <Link
                                className="button button--primary button--lg"
                                href="/onboarding"
                            >
                                <span>Как это работает</span>
                                <Icon name="arrow-right" size={20} />
                            </Link>
                            <p className="welcome-footnote">
                                Вход в рабочее пространство подтверждается Telegram Mini
                                App на сервере.
                            </p>
                        </>
                    )}
                </div>
            </AppShell>
        </>
    );
}
