import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, GlassCard, InlineAlert } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import type { PageProps } from '../types';

export default function Plans() {
    const { auth } = usePage<PageProps>().props;
    const access = presentAccess(auth.access);

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
                        <p>Текущий доступ</p>
                    </div>
                    <strong>{access.badge}</strong>
                    <span>{access.detail}</span>
                </GlassCard>

                <InlineAlert title="Оплата пока недоступна" tone="neutral">
                    Приём платежей в приложении отключён. Если у вас вопрос о доступе,
                    напишите нам через поддержку.
                </InlineAlert>

                <GlassCard className="billing-details" tone="quiet">
                    <h2>Условия и помощь</h2>
                    <div className="billing-links">
                        <Link href="/offer">Оферта</Link>
                        <Link href="/privacy">Политика</Link>
                        <Link href="/support">Написать в поддержку</Link>
                    </div>
                </GlassCard>
            </AppShell>
        </>
    );
}
