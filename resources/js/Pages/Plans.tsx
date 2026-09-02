import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard, InlineAlert } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import type { PageProps } from '../types';

export default function Plans() {
    const { auth } = usePage<PageProps>().props;
    const access = presentAccess(auth.access);
    const isSuperAdmin = auth.user?.role === 'super_admin';

    return (
        <>
            <Head title="Доступ" />
            <AppShell
                activeNav="/profile"
                backHref="/profile"
                eyebrow="Аккаунт"
                title="Доступ"
            >
                <section className="plans-intro page-enter">
                    <Badge tone={access.tone}>{access.badge}</Badge>
                    <h2>{access.title}</h2>
                    <p>{access.description}</p>
                </section>

                <GlassCard
                    className="access-state-card page-enter page-enter--delay"
                    tone="accent"
                >
                    <div>
                        <span className="access-state-card__icon">
                            <Icon name="shield" size={20} />
                        </span>
                        <p>Текущий статус</p>
                    </div>
                    <strong>{access.badge}</strong>
                    <span>{access.detail}</span>
                </GlassCard>

                <InlineAlert title="Оплата пока не подключена" tone="neutral">
                    Telegram Stars, счета и управление подпиской ещё не запущены. Этот
                    экран не создаёт платежи и не меняет доступ.
                </InlineAlert>

                {isSuperAdmin ? (
                    <GlassCard
                        className="access-admin page-enter page-enter--later"
                        tone="quiet"
                    >
                        <div>
                            <p>Дополнительные инструменты</p>
                            <h2>Поиск и аналитика</h2>
                            <span>
                                Роль владельца расширяет возможности, но не заменяет
                                пользовательский доступ.
                            </span>
                        </div>
                        <div>
                            <Link href="/mvp/workspace">
                                Открыть поиск ЕИС{' '}
                                <Icon name="chevron-right" size={16} />
                            </Link>
                            <Link href="/operations">
                                Открыть аналитику{' '}
                                <Icon name="chevron-right" size={16} />
                            </Link>
                        </div>
                    </GlassCard>
                ) : null}
            </AppShell>
        </>
    );
}
