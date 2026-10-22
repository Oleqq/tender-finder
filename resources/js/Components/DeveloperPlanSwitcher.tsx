import '../../css/developer-plan-switcher.css';

import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, GlassCard } from './ui';

export type DeveloperMode = {
    selected_plan: 'basic' | 'pro' | 'ultra' | null;
    plans: {
        code: 'basic' | 'pro' | 'ultra';
        name: string;
        active_query_limit: number;
        analytics: string;
    }[];
};

export function DeveloperPlanSwitcher({ mode }: { mode: DeveloperMode }) {
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');

    const selectPlan = (code: DeveloperMode['selected_plan']) => {
        if (busy || code === mode.selected_plan) return;
        setNotice('');
        setError('');
        router.put(
            '/profile/developer-plan',
            { plan_code: code },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setBusy(true),
                onSuccess: () =>
                    setNotice(
                        code === null
                            ? 'Исходный доступ восстановлен.'
                            : `Тестовый тариф ${mode.plans.find((plan) => plan.code === code)?.name ?? code} включён. Откройте карточку тендера для проверки аналитики.`,
                    ),
                onError: () =>
                    setError(
                        'Не удалось переключить тариф. Обновите страницу и повторите попытку.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <GlassCard className="developer-plan-switcher" tone="quiet">
            <div className="developer-plan-switcher__heading">
                <div>
                    <p>Тестирование функций</p>
                    <h2>Developer Mode</h2>
                </div>
                <Badge tone="neutral">
                    {mode.selected_plan === null
                        ? 'Исходный доступ'
                        : mode.plans.find((plan) => plan.code === mode.selected_plan)
                              ?.name}
                </Badge>
            </div>
            <p className="developer-plan-switcher__description">
                Выберите тариф для своего аккаунта. Аналитика и лимит новых мониторингов
                будут работать по выбранному плану.
            </p>
            <fieldset disabled={busy} aria-busy={busy}>
                <legend className="sr-only">Тестовый тариф</legend>
                <div className="developer-plan-switcher__plans">
                    {mode.plans.map((plan) => (
                        <button
                            key={plan.code}
                            type="button"
                            data-plan={plan.code}
                            aria-pressed={mode.selected_plan === plan.code}
                            onClick={() => selectPlan(plan.code)}
                        >
                            <strong>{plan.name}</strong>
                            <span>{plan.active_query_limit} мониторингов</span>
                            <small>{plan.analytics} аналитика</small>
                        </button>
                    ))}
                </div>
                <button
                    className="developer-plan-switcher__reset"
                    type="button"
                    disabled={mode.selected_plan === null}
                    onClick={() => selectPlan(null)}
                >
                    Вернуть исходный доступ
                </button>
            </fieldset>
            <p className="developer-plan-switcher__note">
                Выбор сохраняется между входами. Существующие мониторинги и заявки
                сохраняются; новый лимит применяется при создании и включении
                мониторингов. Подписки и платежи не меняются.
            </p>
            <div aria-live="polite" role="status">
                {busy ? (
                    <p className="developer-plan-switcher__notice">
                        Переключаем тариф…
                    </p>
                ) : notice ? (
                    <p className="developer-plan-switcher__notice">{notice}</p>
                ) : null}
            </div>
            {error ? (
                <p className="developer-plan-switcher__error" role="alert">
                    {error}
                </p>
            ) : null}
        </GlassCard>
    );
}
