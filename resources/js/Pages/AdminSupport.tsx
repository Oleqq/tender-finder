import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Badge, GlassCard } from '../Components/ui';
import {
    categoryLabel,
    statusLabel,
    supportDate,
    type SupportStatus,
    type SupportTicketSummary,
} from '../lib/support';
import type { PageProps } from '../types';

type Paginator = {
    data: SupportTicketSummary[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function AdminSupport() {
    const { tickets, statusFilter } =
        usePage<PageProps<{ tickets: Paginator; statusFilter: SupportStatus | null }>>()
            .props;

    return (
        <>
            <Head title="Обращения" />
            <AppShell
                backHref="/profile"
                eyebrow="Поддержка · администратор"
                title="Обращения"
                wide
            >
                <div className="space-y-4">
                    <div className="flex flex-wrap gap-2">
                        {(
                            [
                                [null, 'Все'],
                                ['open', 'Новые'],
                                ['in_progress', 'В работе'],
                                ['resolved', 'Решённые'],
                            ] as const
                        ).map(([status, label]) => (
                            <Link
                                aria-current={
                                    status === statusFilter ? 'page' : undefined
                                }
                                className="filter-chip"
                                href={
                                    status
                                        ? `/support/admin?status=${status}`
                                        : '/support/admin'
                                }
                                key={label}
                            >
                                {label}
                            </Link>
                        ))}
                    </div>
                    {tickets.data.length === 0 ? (
                        <GlassCard>Обращений с таким статусом нет.</GlassCard>
                    ) : null}
                    {tickets.data.map((ticket) => (
                        <GlassCard key={ticket.id}>
                            <Link
                                className="block space-y-2"
                                href={`/support/admin/${ticket.id}`}
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <strong>
                                        #{ticket.id} · {categoryLabel[ticket.category]}
                                    </strong>
                                    <Badge>{statusLabel[ticket.status]}</Badge>
                                </div>
                                <p>Пользователь #{ticket.user_id}</p>
                                <small>
                                    Ответственный:{' '}
                                    {ticket.assignee_id === null
                                        ? 'не назначен'
                                        : `#${ticket.assignee_id}`}{' '}
                                    · Обновлено {supportDate(ticket.updated_at)}
                                </small>
                            </Link>
                        </GlassCard>
                    ))}
                    <div className="flex items-center justify-between gap-3">
                        {tickets.prev_page_url ? (
                            <Link href={tickets.prev_page_url}>← Назад</Link>
                        ) : (
                            <span />
                        )}
                        <small>
                            {tickets.current_page} / {tickets.last_page}
                        </small>
                        {tickets.next_page_url ? (
                            <Link href={tickets.next_page_url}>Далее →</Link>
                        ) : (
                            <span />
                        )}
                    </div>
                </div>
            </AppShell>
        </>
    );
}
