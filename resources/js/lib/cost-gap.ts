import axios from 'axios';
import { useRef, useState } from 'react';

export type GapStatus = 'included' | 'not_mentioned' | 'clarify';
export type Evidence = { ref: string; text: string; context?: Evidence | null };
export type GapSource = {
    kind: 'text' | 'file' | 'document';
    title: string;
    segments: Evidence[];
    warnings: string[];
};
export type GapRow = {
    key: string;
    title: string;
    status: GapStatus;
    reason: string;
    requirement_evidence: Evidence[];
    offer_evidence: Evidence[];
};
export type GapDecision = { status: GapStatus; note: string; amount: string | null };
export const gapCategories = {
    planned_cost: 'Плановая себестоимость',
    commission_cost: 'Комиссии',
    other_cost: 'Прочие расходы',
} as const;
export type GapCategory = keyof typeof gapCategories;
export type GapCheck = {
    version: number;
    source_hash: string;
    sources: { customer: GapSource; supplier: GapSource } | null;
    manual_requirements: string;
    rows: GapRow[];
    decisions: Record<string, GapDecision>;
    stale: string[];
    economics: Record<GapCategory, string | null> & { version: number };
    transfers: {
        key: string;
        title: string;
        category: GapCategory;
        amount: string;
        previous_amount: string;
        result_amount: string;
        created_at: string;
    }[];
};
export const gapStatuses: Record<GapStatus, string> = {
    included: 'Включено',
    not_mentioned: 'Нет явного упоминания',
    clarify: 'Нужно уточнить',
};
export function gapMoney(value: string | null | undefined): string {
    if (value == null || value === '') return 'Неизвестно';
    const [whole, fraction = '00'] = value.replace(',', '.').split('.');
    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0')},${fraction.padEnd(2, '0')}\u00a0₽`;
}
export function gapSum(
    base: string | null,
    delta: string | null | undefined,
): string | null {
    if (base === null || delta == null) return null;
    const minor = (value: string) => {
        const [whole, fraction = ''] = value.split('.');
        return BigInt(whole) * BigInt(100) + BigInt(fraction.padEnd(2, '0'));
    };
    const total = minor(base) + minor(delta);
    return `${total / BigInt(100)}.${String(total % BigInt(100)).padStart(2, '0')}`;
}
export function useGapAction() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(false);
    const [message, setMessage] = useState('');
    const lock = useRef(false);
    async function run(
        method: 'post' | 'patch',
        url: string,
        data: unknown,
        success: string,
    ) {
        if (lock.current) return null;
        lock.current = true;
        setBusy(true);
        setError('');
        setConflict(false);
        setMessage('');
        try {
            const result = await axios.request<{ check: GapCheck }>({
                method,
                url,
                data,
            });
            setMessage(success);
            return result.data.check;
        } catch (reason) {
            if (axios.isAxiosError(reason)) {
                const errors = reason.response?.data?.errors as
                    Record<string, string[]> | undefined;
                setError(
                    errors
                        ? Object.values(errors).flat().join(' ')
                        : (reason.response?.data?.message ??
                              'Нет связи с сервером. Введённые данные остались в форме.'),
                );
                setConflict(reason.response?.status === 409);
            } else
                setError(
                    'Не удалось выполнить запрос. Введённые данные остались в форме.',
                );
            return null;
        } finally {
            setBusy(false);
            lock.current = false;
        }
    }
    return { busy, error, conflict, message, run };
}
