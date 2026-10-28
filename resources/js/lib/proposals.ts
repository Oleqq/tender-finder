import axios from 'axios';
import { useRef, useState } from 'react';

export type ProposalBlock = {
    id: number;
    title: string;
    category: string;
    body: string;
    version: number;
};
export type ProposalDraft = {
    id: number;
    body: string;
    version: number;
    missing: string[];
    stale_blocks: string[];
    tender_changed: boolean;
    fields: Record<string, string>;
    block_ids: number[];
    template_id: number | null;
};
export const proposalCategories: Record<string, string> = {
    services: 'Услуги',
    experience: 'Опыт и портфолио',
    works: 'Состав работ',
    delivery: 'Сроки',
    warranty: 'Гарантии',
    team: 'Команда',
    template: 'Шаблон предложения',
};
export function useProposalAction() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const lock = useRef(false);
    async function run<T>(
        method: 'post' | 'patch' | 'delete',
        url: string,
        data: unknown,
        success: string,
    ): Promise<T | null> {
        if (lock.current) return null;
        lock.current = true;
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const result = await axios.request<T>({ method, url, data });
            setMessage(success);
            return result.data;
        } catch (reason) {
            setError(
                axios.isAxiosError(reason)
                    ? (reason.response?.data?.message ??
                          'Нет связи с сервером. Изменения сохранены в форме.')
                    : 'Не удалось выполнить запрос.',
            );
            return null;
        } finally {
            setBusy(false);
            lock.current = false;
        }
    }
    return { busy, error, message, run };
}
