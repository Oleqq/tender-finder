import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button, InlineAlert } from './ui';
import { useProposalAction } from '../lib/proposals';
import {
    emptyWinnerEvidence,
    winnerEvidenceFields,
    type WinnerEvidence,
} from '../lib/winnerOpportunities';

export function WinnerEvidenceForm({
    id,
    version = 0,
    evidence,
    onCancel,
}: {
    id?: number;
    version?: number;
    evidence?: WinnerEvidence;
    onCancel: () => void;
}) {
    const [values, setValues] = useState(evidence ?? emptyWinnerEvidence);
    const [confirmed, setConfirmed] = useState(false);
    const [key] = useState(() => crypto.randomUUID());
    const action = useProposalAction();
    async function save() {
        const result = await action.run<{ id: number }>(
            id ? 'patch' : 'post',
            id ? `/winner-opportunities/${id}` : '/winner-opportunities',
            { ...values, version, confirmed, creation_key: key },
            'Источник сохранён.',
        );
        if (result)
            router.visit(`/winner-opportunities/${result.id}`, {
                preserveState: false,
            });
    }
    return (
        <form
            className="proposal-form"
            onSubmit={(e) => {
                e.preventDefault();
                void save();
            }}
        >
            <p>
                Сверьте оригинал итогового протокола или реестра. Статус «Закупка
                завершена» сам по себе не подтверждает имя победителя. Все данные здесь
                вводите вы; приложение не проверяет содержание ссылки.
            </p>
            {winnerEvidenceFields.map((field) => (
                <label key={field.key}>
                    {field.label}
                    {field.multiline ? (
                        <textarea
                            rows={4}
                            required
                            maxLength={field.max}
                            value={values[field.key]}
                            onChange={(e) =>
                                setValues({ ...values, [field.key]: e.target.value })
                            }
                        />
                    ) : (
                        <input
                            required
                            type={field.type ?? 'text'}
                            maxLength={field.max}
                            value={values[field.key]}
                            onChange={(e) =>
                                setValues({ ...values, [field.key]: e.target.value })
                            }
                        />
                    )}
                </label>
            ))}
            <p>
                Последнее поле — ваше описание интересующей части закупки. Совпадения с
                прайсом будут предварительными.
            </p>
            <label className="proposal-check">
                <input
                    type="checkbox"
                    checked={confirmed}
                    onChange={(e) => setConfirmed(e.target.checked)}
                />
                <span>
                    Я сверил победителя и предмет закупки с оригиналом. Цитата и ссылка
                    относятся к этой закупке.
                </span>
            </label>
            {id ? (
                <p>
                    Изменение источника потребует заново собрать черновик. История
                    обращений сохранится.
                </p>
            ) : null}
            {action.error ? (
                <InlineAlert title="Не удалось сохранить" tone="danger">
                    {action.error}
                </InlineAlert>
            ) : null}
            <div className="proposal-actions">
                <Button type="submit" disabled={action.busy || !confirmed}>
                    Сохранить источник
                </Button>
                <Button variant="ghost" disabled={action.busy} onClick={onCancel}>
                    Отмена
                </Button>
            </div>
        </form>
    );
}
