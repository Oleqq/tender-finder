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
    completed: boolean;
    version: number;
};
export type Participation = {
    stage: Stage;
    loss_reason: string | null;
    version: number;
    items: ChecklistItem[];
    history: Array<{
        id: number;
        from_stage: Stage | null;
        to_stage: Stage;
        reason: string | null;
        created_at: string;
    }>;
};
