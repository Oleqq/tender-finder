export type TenderSummary = {
    budget_amount: string | null;
    currency: string;
    published_at: string | null;
    deadline_at: string | null;
    observed_at: string | null;
    source_label: string;
    application_security: string | null;
    contract_security: string | null;
    delivery_place: string | null;
};

export function validDate(value: string | null): Date | null {
    if (!value) return null;
    const date = new Date(value);
    return Number.isFinite(date.getTime()) ? date : null;
}

export function summaryMoney(value: string | null, currency: string): string {
    if (value === null || value.trim() === '' || !Number.isFinite(Number(value))) {
        return 'Не указан';
    }
    const amount = Number(value);
    try {
        return new Intl.NumberFormat('ru-RU', {
            style: 'currency',
            currency: currency || 'RUB',
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        }).format(amount);
    } catch {
        return (
            new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(
                amount,
            ) +
            ' ' +
            currency
        );
    }
}

export function summaryDate(value: string | null): string {
    const date = validDate(value);
    if (!date) return 'Не указана';
    return (
        new Intl.DateTimeFormat('ru-RU', {
            timeZone: 'Europe/Moscow',
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(date) + ' МСК'
    );
}

export function deadlineSummary(summary: TenderSummary, now = Date.now()) {
    const deadline = validDate(summary.deadline_at)?.getTime();
    const published = validDate(summary.published_at)?.getTime();
    const contradictory =
        deadline !== undefined && published !== undefined && deadline < published;
    const remaining = deadline === undefined ? null : deadline - now;
    const elapsed = remaining !== null && remaining <= 0;
    const progress =
        deadline !== undefined &&
        published !== undefined &&
        published <= now &&
        deadline > published
            ? Math.max(
                  0,
                  Math.min(100, ((now - published) / (deadline - published)) * 100),
              )
            : null;
    let label = 'Срок не указан';
    if (contradictory) {
        label = 'Уточните даты';
    } else if (elapsed) {
        label = 'Срок подачи истёк';
    } else if (remaining !== null) {
        const minutes = Math.floor(remaining / 60_000);
        const days = Math.floor(minutes / 1440);
        const hours = Math.floor((minutes % 1440) / 60);
        label =
            minutes < 1
                ? 'Меньше минуты'
                : days > 0
                  ? days + ' дн. ' + hours + ' ч'
                  : hours > 0
                    ? hours + ' ч ' + (minutes % 60) + ' мин'
                    : minutes + ' мин';
    }
    const tone: 'neutral' | 'success' | 'warning' =
        contradictory || remaining === null || elapsed || remaining <= 86_400_000
            ? 'warning'
            : 'neutral';
    return { label, tone, elapsed, contradictory, remaining, progress };
}
