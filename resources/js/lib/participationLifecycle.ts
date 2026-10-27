import axios from 'axios';
import { scopedUrl } from './workspace';

export type ParticipationTarget = { id: number; version: number; title?: string };

export async function changeParticipations(
    action: 'remove' | 'restore',
    items: ParticipationTarget[],
    team?: { id: number } | null,
): Promise<ParticipationTarget[]> {
    const response = await window.axios.post<{ items: ParticipationTarget[] }>(
        scopedUrl(`/participation/${action}`, team),
        { items: items.map(({ id, version }) => ({ id, version })) },
    );
    return response.data.items;
}

export function participationError(error: unknown): string {
    if (
        axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(
            error,
        )
    ) {
        return (
            Object.values(error.response?.data.errors ?? {}).flat()[0] ??
            error.response?.data.message ??
            'Не удалось изменить список. Попробуйте ещё раз.'
        );
    }
    return 'Не удалось связаться с сервером. Проверьте соединение.';
}
