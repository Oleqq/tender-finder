export type WinnerEvidence = {
    title: string;
    winner_name: string;
    source_url: string;
    source_reference: string;
    source_quote: string;
    result_on: string;
    checked_on: string;
    scope: string;
};
export type WinnerItem = {
    id: number;
    name: string;
    characteristics: string | null;
    price: string | null;
    quantity: string | null;
    unit: string | null;
    region: string | null;
};
export type WinnerOpportunity = {
    id: number;
    version: number;
    evidence: WinnerEvidence;
    body: string | null;
    draft_source: {
        items: WinnerItem[];
        company: string;
        contacts: string;
        rationale: string;
        catalog_version: number;
    } | null;
    stale: boolean;
    archived: boolean;
    outcome: string;
    outcome_note: string | null;
};
export type WinnerPage<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
export const winnerOutcomes: Record<string, string> = {
    new: 'Изучаю',
    contacted: 'Обращение зафиксировано',
    reply: 'Получен ответ',
    requested: 'Есть запрос на поставку',
    declined: 'Отказ после обращения',
    unsuitable: 'Не подходит',
};
export const winnerEvidenceFields: {
    key: keyof WinnerEvidence;
    label: string;
    max: number;
    type?: string;
    multiline?: boolean;
}[] = [
    { key: 'title', label: 'Закупка и номер извещения', max: 300 },
    { key: 'winner_name', label: 'Победитель — точное название', max: 300 },
    { key: 'source_url', label: 'HTTPS-ссылка на итоги', max: 2000, type: 'url' },
    { key: 'source_reference', label: 'Документ, номер и место цитаты', max: 500 },
    {
        key: 'source_quote',
        label: 'Цитата, подтверждающая победителя',
        max: 6000,
        multiline: true,
    },
    { key: 'result_on', label: 'Дата итогов', max: 10, type: 'date' },
    { key: 'checked_on', label: 'Дата вашей проверки', max: 10, type: 'date' },
    {
        key: 'scope',
        label: 'Что из предмета закупки можете предложить',
        max: 3000,
        multiline: true,
    },
];
export const emptyWinnerEvidence: WinnerEvidence = {
    title: '',
    winner_name: '',
    source_url: '',
    source_reference: '',
    source_quote: '',
    result_on: '',
    checked_on: '',
    scope: '',
};
