import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard, InlineAlert } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import type { PageProps } from '../types';

export default function Dashboard() {
    const { auth } = usePage<PageProps>().props;
    const access = presentAccess(auth.access);
    const isSuperAdmin = auth.user?.role === 'super_admin';
    const canUseMonitoring = ['trialing', 'active'].includes(auth.access?.state ?? '');

    return (
        <>
            <Head title="Обзор" />
            <AppShell
                activeNav="/dashboard"
                eyebrow="Tender Finder"
                title="Рабочее пространство"
            >
                <section className="dashboard-hero page-enter">
                    <div>
                        <Badge tone={access.tone}>{access.badge}</Badge>
                        <h2>
                            Тендеры
                            <br />
                            <em>в фокусе.</em>
                        </h2>
                        <p>
                            {canUseMonitoring
                                ? 'Создавайте мониторинги и разбирайте только те закупки, которые совпали с вашими условиями.'
                                : 'Профиль готов. Мониторинги и новые совпадения станут доступны после активации доступа.'}
                        </p>
                    </div>
                    <span aria-hidden="true" className="dashboard-signal">
                        <Icon name="wave" size={18} />
                        <span>поток</span>
                    </span>
                </section>

                <GlassCard
                    className="workspace-card page-enter page-enter--delay"
                    tone="accent"
                >
                    <div className="workspace-card__icon">
                        <Icon name="tenders" size={21} />
                    </div>
                    <div>
                        <p>Ваш поток</p>
                        <h3>Совпадения по мониторингам</h3>
                        <span>
                            {canUseMonitoring
                                ? 'Когда сервер найдёт подходящую закупку, карточка появится в ленте с причиной совпадения.'
                                : 'Здесь появится ваша лента после активации доступа и первого мониторинга.'}
                        </span>
                    </div>
                    <Link
                        aria-label={
                            canUseMonitoring
                                ? 'Открыть мои тендеры'
                                : 'Открыть информацию о доступе'
                        }
                        className="icon-button icon-button--soft"
                        href={canUseMonitoring ? '/tenders' : '/plans'}
                    >
                        <Icon name="chevron-right" size={20} />
                    </Link>
                </GlassCard>

                <section className="dashboard-section page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Следующий шаг</p>
                            <h2>
                                {canUseMonitoring
                                    ? 'Настройте мониторинг'
                                    : 'Активируйте доступ'}
                            </h2>
                        </div>
                        <Link href={canUseMonitoring ? '/queries' : '/plans'}>
                            {canUseMonitoring ? 'Мониторинги' : 'Подробнее'}{' '}
                            <Icon name="chevron-right" size={16} />
                        </Link>
                    </div>
                    <InlineAlert title="Как работает поток" tone="neutral">
                        {canUseMonitoring
                            ? 'Мониторинг хранит ваши условия. В ленту попадают только серверные совпадения, а не рекомендации или примерные карточки.'
                            : 'Доступ рассчитывается на сервере. Мы не показываем форму мониторинга, пока его нет, и не создаём тестовые данные.'}
                    </InlineAlert>
                </section>

                {isSuperAdmin ? (
                    <GlassCard
                        className="workspace-admin page-enter page-enter--later"
                        tone="quiet"
                    >
                        <div>
                            <p>Дополнительные инструменты</p>
                            <h3>Поиск ЕИС и аналитика продукта</h3>
                            <span>
                                Они доступны только в роли владельца и не меняют ваш
                                пользовательский поток.
                            </span>
                        </div>
                        <div className="workspace-admin__actions">
                            <Link href="/mvp/workspace">
                                Поиск ЕИС <Icon name="chevron-right" size={16} />
                            </Link>
                            <Link href="/operations">
                                Аналитика <Icon name="chevron-right" size={16} />
                            </Link>
                        </div>
                    </GlassCard>
                ) : null}
            </AppShell>
        </>
    );
}
