import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import type { PageProps } from '../types';

export default function Profile() {
    const { auth } = usePage<PageProps>().props;
    const access = presentAccess(auth.access);
    const isSuperAdmin = auth.user?.role === 'super_admin';
    const initials = (auth.user?.name ?? 'Tender Finder')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();

    return (
        <>
            <Head title="Профиль" />
            <AppShell activeNav="/profile" eyebrow="Аккаунт" title="Профиль">
                <section className="profile-hero page-enter">
                    <span className="profile-avatar">{initials}</span>
                    <div>
                        <Badge tone={isSuperAdmin ? 'success' : 'accent'}>
                            {isSuperAdmin ? 'Расширенный доступ' : 'Аккаунт'}
                        </Badge>
                        <h2>{auth.user?.name ?? 'Ваш профиль'}</h2>
                        <p>Роль и доступ подтверждаются серверной сессией.</p>
                    </div>
                </section>

                <GlassCard
                    className="profile-plan page-enter page-enter--delay"
                    tone="accent"
                >
                    <div className="profile-plan__header">
                        <span>
                            <Icon name="spark" size={19} /> Статус доступа
                        </span>
                        <Badge tone={access.tone}>{access.badge}</Badge>
                    </div>
                    <h3>{access.title}</h3>
                    <p>{access.description}</p>
                    <div className="profile-plan__line">
                        <span>Период</span>
                        <strong>{access.detail}</strong>
                    </div>
                </GlassCard>

                <section className="profile-section page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Аккаунт</p>
                            <h2>Данные и доступ</h2>
                        </div>
                    </div>
                    <GlassCard className="settings-list">
                        <div className="settings-row">
                            <span>
                                <strong>Безопасность сессии</strong>
                                <small>
                                    Telegram ID и технические данные не показываются в
                                    интерфейсе.
                                </small>
                            </span>
                            <Icon name="shield" size={19} />
                        </div>
                    </GlassCard>
                </section>

                <section className="profile-help page-enter page-enter--later">
                    <Icon name="shield" size={18} />
                    <p>
                        Доступ рассчитывается на сервере. Этот экран не изменяет его
                        локальными настройками.
                    </p>
                </section>

                {isSuperAdmin ? (
                    <GlassCard className="profile-admin" tone="quiet">
                        <span>
                            <Icon name="shield" size={18} /> Инструменты владельца
                        </span>
                        <p>
                            Поиск ЕИС и агрегированная аналитика продукта без
                            персональных данных.
                        </p>
                        <div>
                            <Link href="/mvp/workspace">
                                Поиск ЕИС <Icon name="chevron-right" size={16} />
                            </Link>
                            <Link href="/operations">
                                Открыть аналитику{' '}
                                <Icon name="chevron-right" size={16} />
                            </Link>
                        </div>
                    </GlassCard>
                ) : null}
                <Link className="profile-plans-link" href="/plans">
                    Подробнее о доступе <Icon name="chevron-right" size={17} />
                </Link>
            </AppShell>
        </>
    );
}
