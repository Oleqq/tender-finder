import { Link } from '@inertiajs/react';
import { BottomSheet } from './ui';

type ComparisonTender = {
    id: number;
    title: string;
    region: string | null;
    budget_amount: string | null;
    currency: string;
    published_at: string | null;
    deadline_at: string | null;
    reg_number: string | null;
    customer: string | null;
    category: string | null;
    procurement_law: string | null;
    canonical_url: string;
    status: string;
    match_reason: { matched_terms: string[] } | null;
};

export function TenderComparison({
    tenders,
    open,
    onClose,
}: {
    tenders: ComparisonTender[];
    open: boolean;
    onClose: () => void;
}) {
    return (
        <BottomSheet
            onClose={onClose}
            open={open}
            title={`Сравнение · ${tenders.length}`}
        >
            <p className="sheet-description">
                Поля сравниваются только по данным, которые вернула ЕИС.
            </p>
            <div className="tender-comparison">
                <table>
                    <thead>
                        <tr>
                            <th>Параметр</th>
                            {tenders.map((tender) => (
                                <th key={tender.id}>{tender.title}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        <ComparisonRow label="НМЦК" tenders={tenders} value={budget} />
                        <ComparisonRow
                            label="Заказчик"
                            tenders={tenders}
                            value={(tender) => tender.customer}
                        />
                        <ComparisonRow
                            label="Закон"
                            tenders={tenders}
                            value={(tender) =>
                                tender.procurement_law
                                    ? `${tender.procurement_law}-ФЗ`
                                    : null
                            }
                        />
                        <ComparisonRow
                            label="Процедура"
                            tenders={tenders}
                            value={(tender) => tender.category}
                        />
                        <ComparisonRow
                            label="Регион"
                            tenders={tenders}
                            value={(tender) => tender.region}
                        />
                        <ComparisonRow
                            label="Опубликовано"
                            tenders={tenders}
                            value={(tender) => date(tender.published_at)}
                        />
                        <ComparisonRow
                            label="Срок подачи"
                            tenders={tenders}
                            value={(tender) => date(tender.deadline_at)}
                        />
                        <ComparisonRow
                            label="Совпадение"
                            tenders={tenders}
                            value={(tender) =>
                                tender.match_reason?.matched_terms.join(', ') ?? null
                            }
                        />
                        <ComparisonRow
                            label="Номер ЕИС"
                            tenders={tenders}
                            value={(tender) => tender.reg_number}
                        />
                        <tr>
                            <th>Действия</th>
                            {tenders.map((tender) => (
                                <td key={tender.id}>
                                    <Link href={`/local/mvp/tenders/${tender.id}`}>
                                        Карточка
                                    </Link>
                                    <a
                                        href={tender.canonical_url}
                                        rel="noreferrer"
                                        target="_blank"
                                    >
                                        ЕИС
                                    </a>
                                </td>
                            ))}
                        </tr>
                    </tbody>
                </table>
            </div>
        </BottomSheet>
    );
}

function ComparisonRow({
    label,
    tenders,
    value,
}: {
    label: string;
    tenders: ComparisonTender[];
    value: (tender: ComparisonTender) => string | null;
}) {
    return (
        <tr>
            <th>{label}</th>
            {tenders.map((tender) => (
                <td key={tender.id}>{value(tender) ?? 'Нет данных'}</td>
            ))}
        </tr>
    );
}

function budget(tender: ComparisonTender): string | null {
    if (!tender.budget_amount) {
        return null;
    }

    return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(
        Number(tender.budget_amount),
    )} ${tender.currency === 'RUB' ? '₽' : tender.currency}`;
}

function date(value: string | null): string | null {
    return value ? new Intl.DateTimeFormat('ru-RU').format(new Date(value)) : null;
}
