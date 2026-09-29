export type SupportStatus = 'open' | 'in_progress' | 'resolved';
export type SupportCategory = 'access' | 'monitoring' | 'notifications' | 'other';

export type SupportTicketSummary = {
    id: number;
    user_id: number;
    category: SupportCategory;
    status: SupportStatus;
    assignee_id: number | null;
    created_at: string;
    updated_at: string;
};

export type SupportTicketDetail = SupportTicketSummary & {
    messages: Array<{
        id: number;
        is_staff: boolean;
        body: string;
        created_at: string;
    }>;
    events?: Array<{
        id: number;
        actor_id: number | null;
        action: string;
        old_status: SupportStatus | null;
        new_status: SupportStatus | null;
        old_assignee_id: number | null;
        new_assignee_id: number | null;
        reason: string | null;
        created_at: string;
    }>;
};

export const categoryLabel: Record<SupportCategory, string> = {
    access: 'Доступ',
    monitoring: 'Мониторинг',
    notifications: 'Уведомления',
    other: 'Другое',
};

export const statusLabel: Record<SupportStatus, string> = {
    open: 'Новое',
    in_progress: 'В работе',
    resolved: 'Решено',
};

export function supportDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}
