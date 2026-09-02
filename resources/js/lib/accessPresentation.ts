import type { Access } from '../types';

type AccessTone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';

export type AccessPresentation = {
    badge: string;
    title: string;
    description: string;
    detail: string;
    tone: AccessTone;
};

export function presentAccess(access: Access | null): AccessPresentation {
    const period = access?.ends_at
        ? `до ${formatDate(access.ends_at)}`
        : 'без даты окончания';

    switch (access?.state) {
        case 'trialing':
            return {
                badge: 'Пробный доступ',
                title: 'Доступ активен',
                description:
                    'Можно работать с доступными функциями в рамках пробного периода.',
                detail: period,
                tone: 'success',
            };
        case 'active':
            return {
                badge: 'Доступ активен',
                title: 'Рабочее пространство открыто',
                description: 'Доступ подтверждён сервером для этой сессии.',
                detail: period,
                tone: 'success',
            };
        case 'expired':
            return {
                badge: 'Доступ завершён',
                title: 'Доступ требует продления',
                description:
                    'Сохранённые данные остаются в аккаунте. Оплата будет добавлена отдельным запуском.',
                detail: period,
                tone: 'warning',
            };
        case 'cancelled':
            return {
                badge: 'Доступ отключён',
                title: 'Доступ не активен',
                description:
                    'Рабочие функции станут доступны после следующего подтверждённого назначения доступа.',
                detail: period,
                tone: 'danger',
            };
        case 'preview':
        default:
            return {
                badge: 'Ожидает активации',
                title: 'Доступ ещё не активирован',
                description:
                    'Профиль создан. Рабочие функции откроются после подтверждённого доступа.',
                detail: 'статус проверяется сервером',
                tone: 'accent',
            };
    }
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}
