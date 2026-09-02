import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { TenderCard } from '../Components/TenderCard';
import { EmptyState, GlassCard } from '../Components/ui';
import type { PageProps } from '../types';

type TenderMatch = {
    id: number;
    title: string;
    description: string | null;
    canonical_url: string;
    reg_number: string | null;
    region: string | null;
    budget_amount: string | null;
    currency: string;
    deadline_at: string | null;
    matched_at: string;
    query_name: string;
    match_reasons: string[];
};

type TendersPageProps = PageProps<{
    tenderMatches: TenderMatch[];
}>;

export default function Tenders() {
    const { tenderMatches } = usePage<TendersPageProps>().props;

    return (
        <>
            <Head title="Мои тендеры" />
            <AppShell activeNav="/tenders" eyebrow="Мой поток" title="Тендеры">
                <GlassCard className="tenders-summary page-enter" tone="quiet">
                    <span className="tenders-summary__mark">
                        <Icon name="layers" size={19} />
                    </span>
                    <div>
                        <p>Совпадения по мониторингам</p>
                        <strong>
                            {tenderMatches.length === 0
                                ? 'Новых карточек пока нет'
                                : `${tenderMatches.length} ${tenderWord(tenderMatches.length)} в потоке`}
                        </strong>
                    </div>
                </GlassCard>

                {tenderMatches.length > 0 ? (
                    <section
                        aria-label="Совпавшие тендеры"
                        className="tenders-preview page-enter page-enter--delay"
                    >
                        {tenderMatches.map((match) => (
                            <TenderCard
                                customer={`Мониторинг: ${match.query_name}`}
                                deadline={formatDeadline(match.deadline_at)}
                                description={match.description}
                                href={match.canonical_url}
                                key={match.id}
                                match={`Совпало: ${match.match_reasons.join(', ')}`}
                                price={formatBudget(
                                    match.budget_amount,
                                    match.currency,
                                )}
                                status="Новый"
                                title={match.title}
                            />
                        ))}
                    </section>
                ) : (
                    <div className="tenders-empty page-enter page-enter--later">
                        <EmptyState
                            action={
                                <Link
                                    className="button button--secondary"
                                    href="/queries"
                                >
                                    <Icon name="tenders" size={18} />
                                    <span>Открыть мониторинги</span>
                                </Link>
                            }
                            description="Когда новая закупка совпадёт с активным мониторингом, сервер сохранит её здесь вместе с понятной причиной совпадения."
                            icon="compass"
                            title="Пока нет подходящих закупок"
                        />
                    </div>
                )}

                <section className="empty-hint page-enter page-enter--later">
                    <Icon name="spark" size={17} />
                    <p>
                        Карточки показывают только совпадения по вашим мониторингам. Это
                        не рейтинг и не обещание шанса на победу.
                    </p>
                </section>
            </AppShell>
        </>
    );
}

function tenderWord(count: number): string {
    const remainder = count % 10;
    const teen = count % 100;

    if (teen >= 11 && teen <= 14) {
        return 'карточек';
    }

    if (remainder === 1) {
        return 'карточка';
    }

    if (remainder >= 2 && remainder <= 4) {
        return 'карточки';
    }

    return 'карточек';
}

function formatBudget(value: string | null, currency: string): string {
    if (value === null) {
        return 'Сумма не указана';
    }

    return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Number(value))} ${currency === 'RUB' ? '₽' : currency}`;
}

function formatDeadline(value: string | null): string {
    if (value === null) {
        return 'Срок не указан';
    }

    return `Срок: ${new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(new Date(value))}`;
}
