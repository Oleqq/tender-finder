export { useProposalAction as useProcurementAction } from './proposals';

export type ProcurementNeed = {
    id: number;
    version: number;
    participation_id: number;
    tender_id: number;
    contract: string;
    title: string;
    specification: string | null;
    unit: string;
    quantity: string;
    required_on: string | null;
    confirmed_comparison_id: number | null;
};
export type ProcurementOffer = {
    supplier: string | null;
    reference: string | null;
    quoted_on: string | null;
    valid_until: string | null;
    unit_price: string | null;
    delivery_cost: string | null;
    delivery_on: string | null;
};
export type ProcurementComparison = {
    id: number;
    version: number;
    title: string;
    status: 'draft' | 'confirmed' | 'canceled';
    selection: ProcurementNeed[];
    offers: (ProcurementOffer & { need_id: number })[];
    combined_offer: ProcurementOffer;
    conditions_note: string | null;
    stale_reasons: string[];
    current_reasons: string[];
    confirmed_at: string | null;
    canceled_at: string | null;
    result: {
        complete: boolean;
        reasons: string[];
        quantity: string;
        unit: string;
        separate_total: string | null;
        combined_total: string | null;
        saving: string | null;
        different_specs: boolean;
        different_dates: boolean;
        allocations: {
            need_id: number;
            participation_id: number;
            contract: string;
            quantity: string;
            separate_cost: string | null;
            combined_cost: string | null;
            saving: string | null;
        }[];
    };
};
export const procurementStatus = {
    draft: 'Черновик',
    confirmed: 'Распределено по контрактам',
    canceled: 'Закрыто · не учитывается',
};
export const procurementMoney = (value: string | null) =>
    value === null
        ? 'Не указано'
        : `${Number(value).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₽`;
export const procurementQuantity = (value: string) =>
    Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 3 });
export const procurementDate = (value: string | null) =>
    value ? value.split('-').reverse().join('.') : 'Срок не указан';
