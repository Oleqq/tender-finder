import axios from 'axios';
import { useRef, useState } from 'react';

export type QuestionSource = {
    kind: 'manual' | 'card' | 'costgap';
    title: string;
    ref: string;
    text: string;
    user_supplied: boolean;
    url?: string | null;
    cost_key?: string;
    evidence_index?: number;
};
export type TenderQuestion = {
    id: number;
    title: string;
    topic: string;
    context: string | null;
    body: string;
    source: QuestionSource;
    source_href: string | null;
    source_revision: number;
    version: number;
    stale: string[];
    status: 'draft' | 'sent' | 'answered';
    archived_at: string | null;
    marked_sent_at: string | null;
    sent_reference: string | null;
    answer: string | null;
    answer_source: string | null;
    answer_received_on: string | null;
    answer_updated_at: string | null;
};
export type QuestionSources = {
    card: { ref: string; text: string }[];
    cost_hash: string;
    cost_stale: string[];
    cost: {
        key: string;
        title: string;
        source_title: string;
        evidence: { ref: string; text: string }[];
    }[];
};
export type QuestionResult = { questions: TenderQuestion[]; question_id: number };
export type QuestionEvent = {
    version: number;
    action: string;
    created_at: string;
    snapshot: {
        title: string;
        body: string;
        answer: string | null;
        answer_source: string | null;
        source: QuestionSource;
    };
};
export const questionStatuses = {
    draft: 'Черновик',
    sent: 'Отправлен · ручная отметка',
    answered: 'Ответ сохранён',
};
export const questionEvents: Record<string, string> = {
    created: 'Черновик создан',
    edited: 'Текст изменён',
    recomposed: 'Основание и текст обновлены',
    marked_sent: 'Отправка отмечена пользователем',
    answer_saved: 'Ответ сохранён',
    archived: 'В архиве',
    restored: 'Восстановлен',
};

export function useQuestionAction() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [conflict, setConflict] = useState(false);
    const lock = useRef(false);
    async function run<T>(
        method: 'get' | 'post' | 'patch',
        url: string,
        data: unknown,
        success = '',
    ): Promise<T | null> {
        if (lock.current) return null;
        lock.current = true;
        setBusy(true);
        setError('');
        setMessage('');
        setConflict(false);
        try {
            const result = await axios.request<T>({ method, url, data });
            setMessage(success);
            return result.data;
        } catch (reason) {
            if (axios.isAxiosError(reason)) {
                const errors = reason.response?.data?.errors as
                    | Record<string, string[]>
                    | undefined;
                setError(
                    errors
                        ? Object.values(errors).flat().join(' ')
                        : (reason.response?.data?.message ??
                              'Нет связи с сервером. Введённый текст остался в форме.'),
                );
                setConflict(reason.response?.status === 409);
            } else
                setError(
                    'Не удалось выполнить запрос. Введённый текст остался в форме.',
                );
            return null;
        } finally {
            lock.current = false;
            setBusy(false);
        }
    }
    return { run, busy, error, message, conflict };
}
