import axios from 'axios';
import { useState } from 'react';

export type CatalogItem = {
    id: number;
    name: string;
    characteristics: string | null;
    price: string | null;
    quantity: string | null;
    unit: string | null;
    region: string | null;
};
export type Catalog = {
    version: number;
    filename: string | null;
    items: CatalogItem[];
};
export type MatchStatus = 'matched' | 'uncertain' | 'missing';
export type MatchCheck = {
    label: string;
    state: 'ok' | 'unknown' | 'different';
    detail: string;
};
export type CatalogCandidate = {
    item: CatalogItem;
    checks: MatchCheck[];
    terms: string[];
    score: number;
    status: MatchStatus;
};
export type ComparisonRow = {
    index: number;
    requirement: Omit<CatalogItem, 'id'>;
    status: MatchStatus;
    candidates: CatalogCandidate[];
    stock_check: MatchCheck | null;
    selected: { item: CatalogItem; checks: MatchCheck[] } | null;
    review: { status: MatchStatus; item_id: number | null; note: string | null } | null;
};
export type CatalogComparison = {
    version: number;
    catalog_version: number;
    tender_hash: string;
    filename: string | null;
    has_specification: boolean;
    complete: boolean;
    counts: Record<MatchStatus | 'confirmed', number>;
    rows: ComparisonRow[];
};
export const matchLabels: Record<MatchStatus, string> = {
    matched: 'Найдено',
    uncertain: 'Нужно уточнить',
    missing: 'Не найдено в каталоге',
};
export function catalogMoney(price: string | null): string {
    return price === null
        ? 'Цена не указана'
        : new Intl.NumberFormat('ru-RU', {
              style: 'currency',
              currency: 'RUB',
              maximumFractionDigits: 2,
          }).format(Number(price));
}
export function useCatalogAction() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [conflict, setConflict] = useState(false);
    async function run<T>(work: () => Promise<T>, success = ''): Promise<T | null> {
        setBusy(true);
        setError('');
        setNotice('');
        setConflict(false);
        try {
            const result = await work();
            setNotice(success);
            return result;
        } catch (e) {
            if (
                axios.isAxiosError<{
                    message?: string;
                    errors?: Record<string, string[]>;
                }>(e)
            ) {
                setConflict(e.response?.status === 409);
                setError(
                    Object.values(e.response?.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        e.response?.data.message ||
                        'Не удалось выполнить действие. Проверьте соединение.',
                );
            } else {
                setError('Не удалось выполнить действие. Попробуйте ещё раз.');
            }
            return null;
        } finally {
            setBusy(false);
        }
    }
    return { busy, error, notice, conflict, run };
}
