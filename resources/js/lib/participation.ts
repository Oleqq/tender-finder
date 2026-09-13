export const stages = [
    { value: 'studying', label: 'Изучаем' },
    { value: 'preparing', label: 'Готовим заявку' },
    { value: 'submitted', label: 'Подали' },
    { value: 'won', label: 'Выиграли' },
    { value: 'lost', label: 'Проиграли' },
] as const;
export type Stage = (typeof stages)[number]['value'];
export const stageLabel = (value: string | null): string =>
    stages.find((stage) => stage.value === value)?.label ?? 'Не участвуем';
export type ChecklistItem = {
    id: number;
    title: string;
    due_on: string | null;
    assignee_id: number | null;
    reminder_enabled: boolean;
    completed: boolean;
    version: number;
};
export type Participation = {
    assignee_id: number | null;
    stage: Stage;
    loss_reason: string | null;
    version: number;
    economics: Economics;
    items: ChecklistItem[];
    history: Array<{
        id: number;
        from_stage: Stage | null;
        to_stage: Stage;
        reason: string | null;
        created_at: string;
    }>;
};

export type Economics = {
    planned_revenue: string | null;
    planned_cost: string | null;
    security_cost: string | null;
    commission_cost: string | null;
    other_cost: string | null;
    actual_revenue: string | null;
    actual_cost: string | null;
    decision: 'go' | 'no_go' | null;
    decision_note: string | null;
    version: number;
    planned_expenses: number;
    planned_margin: number;
    planned_margin_percent: number | null;
    actual_margin: number;
};
