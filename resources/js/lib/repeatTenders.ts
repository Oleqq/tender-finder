export type RepeatSnapshot = {
    id: number;
    title: string;
    reg_number: string | null;
    url: string;
    customer: string | null;
    region: string | null;
    budget_amount: string | null;
    currency: string | null;
    published_at: string | null;
    deadline_at: string | null;
    description: string | null;
    description_limited: boolean;
    stage: string | null;
};
export type RepeatWatch = {
    id: number;
    version: number;
    reason: string;
    original: RepeatSnapshot;
    archived: boolean;
    available: boolean;
    captured_at: string | null;
    unread: number;
    checked_at: string | null;
    scan_limited: boolean;
};
export type RepeatCandidate = {
    id: number;
    version: number;
    status: string;
    snapshot: RepeatSnapshot;
    latest: RepeatSnapshot | null;
    latest_hash: string | null;
    changed: boolean;
    basis: { kind: string; terms: string[]; customer?: string };
    evidence: Record<string, string> | null;
    decision_note: string | null;
    unread: boolean;
};
export type RepeatPage<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
export const repeatStatus: Record<string, string> = {
    possible: 'Возможный повтор · нужна проверка',
    confirmed: 'Связь подтверждена вами',
    dismissed: 'Отклонено',
};
export function repeatDate(value: string | null) {
    return value
        ? new Date(value).toLocaleString('ru-RU', { timeZone: 'Europe/Moscow' }) +
              ' МСК'
        : 'Не указано';
}
export function safeRepeatUrl(value: string) {
    return /^https?:\/\//i.test(value) ? value : undefined;
}
