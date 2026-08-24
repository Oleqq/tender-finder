export type DemoAccessState = 'preview' | 'trialing' | 'active' | 'expired';

export type DemoAccessStateDetails = {
    label: string;
    title: string;
    description: string;
    detail: string;
    tone: 'neutral' | 'accent' | 'success' | 'warning';
};

export const demoAccessStateOptions: Array<{ value: DemoAccessState; label: string }> =
    [
        { value: 'preview', label: 'Preview' },
        { value: 'trialing', label: 'Trial' },
        { value: 'active', label: 'Basic' },
        { value: 'expired', label: 'Истёк' },
    ];

export const demoAccessStates: Record<DemoAccessState, DemoAccessStateDetails> = {
    preview: {
        label: 'Preview',
        title: 'Можно изучить demo',
        description:
            'Функции показаны на примерах. Доступ к мониторингу и данным ещё не активирован.',
        detail: 'Без сохранения и оплаты',
        tone: 'neutral',
    },
    trialing: {
        label: 'Trial · пример',
        title: '72 часа, чтобы оценить фокус',
        description:
            'Настоящий trial начнётся один раз только после защищённой серверной активации.',
        detail: 'Таймер в demo не запускается',
        tone: 'accent',
    },
    active: {
        label: 'Basic · пример',
        title: 'Basic открыт в примере',
        description:
            'Реальный доступ выдаст только сервер после идемпотентно обработанного payment event.',
        detail: 'Не является entitlement',
        tone: 'success',
    },
    expired: {
        label: 'Доступ завершён · пример',
        title: 'Данные остаются, изменения заморожены',
        description:
            'После завершения Basic запросы не удаляются: их будущий серверный статус будет frozen.',
        detail: 'Без удаления пользовательских данных',
        tone: 'warning',
    },
};
