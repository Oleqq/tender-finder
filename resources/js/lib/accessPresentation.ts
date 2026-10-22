import type { Access } from '../types';

type AccessTone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';

export type AccessPresentation = {
    badge: string;
    title: string;
    description: string;
    detail: string;
    tone: AccessTone;
    phase: 'preview' | 'trial' | 'trial_ending' | 'active' | 'expired' | 'cancelled';
};

export function presentAccess(
    access: Access | null,
    now = new Date(),
): AccessPresentation {
    const end = access?.ends_at ? new Date(access.ends_at) : null;
    const endTime = end?.getTime();
    const validEnd = endTime !== undefined && Number.isFinite(endTime);
    const remainingMs = validEnd ? endTime - now.getTime() : null;
    const period = validEnd ? `до ${formatDate(end!)}` : 'без даты окончания';

    if (access?.state === 'trialing') {
        if (remainingMs !== null && remainingMs <= 0) {
            return expired(period);
        }

        const ending = remainingMs !== null && remainingMs <= 24 * 60 * 60 * 1000;
        const remaining =
            remainingMs === null
                ? period
                : remainingMs < 60 * 60 * 1000
                  ? `меньше часа · ${period}`
                  : remainingMs < 24 * 60 * 60 * 1000
                    ? `${Math.ceil(remainingMs / 3_600_000)} ч · ${period}`
                    : `${Math.ceil(remainingMs / 86_400_000)} дн. · ${period}`;

        return {
            badge: ending ? 'Триал скоро закончится' : 'Пробный доступ',
            title: ending
                ? 'Пробный доступ скоро завершится'
                : 'Пробный доступ активен',
            description: ending
                ? 'Мониторинги остановятся после окончания периода. Сохранённые данные останутся в аккаунте.'
                : 'Ищите тендеры и используйте до трёх активных мониторингов.',
            detail: remaining,
            tone: ending ? 'warning' : 'success',
            phase: ending ? 'trial_ending' : 'trial',
        };
    }

    switch (access?.state) {
        case 'active':
            return {
                badge:
                    access.mode === 'owner'
                        ? 'Доступ владельца'
                        : access.mode === 'developer'
                          ? 'Про (Developer Mode)'
                          : (planName(access.plan_code) ?? 'Доступ активен'),
                title: 'Рабочее пространство открыто',
                description: 'Поиск, мониторинги и работа с закупками доступны.',
                detail: period,
                tone: 'success',
                phase: 'active',
            };
        case 'expired':
            return expired(period);
        case 'cancelled':
            return {
                badge: 'Доступ отключён',
                title: 'Доступ не активен',
                description:
                    'Рабочие функции станут доступны после следующего подтверждённого назначения доступа.',
                detail: period,
                tone: 'danger',
                phase: 'cancelled',
            };
        case 'preview':
        default:
            return {
                badge: '3 дня бесплатно',
                title: 'Начните пробный период',
                description: 'Примите оферту и политику — доступ включится сразу.',
                detail: '72 часа · до 3 мониторингов',
                tone: 'accent',
                phase: 'preview',
            };
    }
}

function planName(code: string | null): string | null {
    switch (code) {
        case 'basic':
            return 'Basic';
        case 'pro':
            return 'Про';
        case 'ultra':
            return 'Ультра';
        default:
            return null;
    }
}

function expired(period: string): AccessPresentation {
    return {
        badge: 'Доступ завершён',
        title: 'Доступ требует продления',
        description:
            'Мониторинги остановлены. Сохранённые настройки и тендеры остаются в аккаунте.',
        detail: period,
        tone: 'warning',
        phase: 'expired',
    };
}

function formatDate(value: Date): string {
    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(value);
}
