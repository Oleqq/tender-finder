import { useState } from 'react';
import { createPortal } from 'react-dom';
import { BottomSheet, Button, FieldError } from './ui';

type Change = {
    id: number;
    created_at: string;
    changes: Record<string, { before: string; after: string }>;
};
const labels: Record<string, string> = {
    deadline_at: 'Срок подачи',
    budget_amount: 'Цена',
    currency: 'Валюта',
    stage: 'Статус',
};

export function TenderFeedbackActions({
    tenderId,
    queryId,
    queryName,
    customer,
    onDismiss,
}: {
    tenderId: number;
    queryId: number;
    queryName: string;
    customer: string | null;
    onDismiss: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('Не соответствует моей нише');
    const [kind, setKind] = useState('');
    const [word, setWord] = useState('');
    const [apply, setApply] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [history, setHistory] = useState<Change[] | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);

    const submit = async () => {
        setBusy(true);
        setError('');
        try {
            await window.axios.post(`/tenders/${tenderId}/feedback`, {
                search_query_id: queryId,
                reason,
                exclusion_kind: kind || null,
                exclusion_value: word || null,
                apply_filter: apply,
            });
            onDismiss();
            setOpen(false);
            setNotice(
                apply
                    ? 'Карточка скрыта. Исключение сохранено для будущих совпадений этого мониторинга.'
                    : 'Карточка скрыта. Причина сохранена.',
            );
        } catch (failure) {
            const data = (
                failure as {
                    response?: { data?: { errors?: Record<string, string[]> } };
                }
            ).response?.data;
            setError(
                Object.values(data?.errors ?? {}).flat()[0] ??
                    'Не удалось сохранить отзыв. Повторите попытку.',
            );
        } finally {
            setBusy(false);
        }
    };
    const showHistory = async () => {
        setBusy(true);
        setError('');
        setHistory(null);
        setHistoryOpen(true);
        try {
            setHistory(
                (
                    await window.axios.get<{ changes: Change[] }>(
                        `/tenders/${tenderId}/changes`,
                    )
                ).data.changes,
            );
        } catch {
            setError(
                'Не удалось загрузить историю. Закройте окно и повторите попытку.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <div className="query-card__actions">
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                        setOpen(true);
                        setError('');
                    }}
                >
                    Не подходит
                </Button>
                <Button size="sm" variant="ghost" disabled={busy} onClick={showHistory}>
                    История изменений
                </Button>
            </div>
            {notice ? <p role="status">{notice}</p> : null}
            {(open || historyOpen) &&
                createPortal(
                    <div className="follow-up-dialogs">
                        <BottomSheet
                            open={open}
                            onClose={() => {
                                if (!busy) setOpen(false);
                            }}
                            title="Почему тендер не подходит?"
                        >
                            <label className="form-field">
                                <span>Причина</span>
                                <textarea
                                    maxLength={500}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                            </label>
                            <label className="form-field">
                                <span>
                                    Предложить изменение мониторинга «{queryName}»
                                </span>
                                <select
                                    value={kind}
                                    onChange={(e) => {
                                        setKind(e.target.value);
                                        setApply(false);
                                    }}
                                >
                                    <option value="">Только скрыть карточку</option>
                                    <option value="keyword">
                                        Исключить слово или фразу
                                    </option>
                                    {customer ? (
                                        <option value="customer">
                                            Исключить заказчика: {customer}
                                        </option>
                                    ) : null}
                                </select>
                            </label>
                            {kind === 'keyword' ? (
                                <label className="form-field">
                                    <span>Минус-слово или фраза</span>
                                    <input
                                        maxLength={100}
                                        value={word}
                                        onChange={(e) => {
                                            setWord(e.target.value);
                                            setApply(false);
                                        }}
                                    />
                                </label>
                            ) : null}
                            {kind ? (
                                <label className="follow-up-toggle">
                                    <input
                                        type="checkbox"
                                        checked={apply}
                                        onChange={(e) => setApply(e.target.checked)}
                                    />
                                    <span>
                                        Подтверждаю: исключить{' '}
                                        {kind === 'customer'
                                            ? `заказчика «${customer}»`
                                            : `«${word || 'указанное слово'}»`}{' '}
                                        из будущих совпадений мониторинга «{queryName}».
                                    </span>
                                </label>
                            ) : null}
                            {error ? <FieldError>{error}</FieldError> : null}
                            <Button
                                className="sheet-action"
                                disabled={
                                    busy ||
                                    !reason.trim() ||
                                    (apply && kind === 'keyword' && !word.trim())
                                }
                                onClick={submit}
                            >
                                {busy
                                    ? 'Сохраняем…'
                                    : apply
                                      ? 'Скрыть и изменить фильтр'
                                      : 'Скрыть и сохранить причину'}
                            </Button>
                        </BottomSheet>
                        <BottomSheet
                            open={historyOpen}
                            onClose={() => setHistoryOpen(false)}
                            title="Изменения закупки"
                        >
                            <p>
                                Изменения цены, срока и статуса, полученные от
                                источника. Показываем последние 30 обновлений.
                            </p>
                            {busy ? <p>Загружаем…</p> : null}
                            {error ? <FieldError>{error}</FieldError> : null}
                            {history?.length === 0 ? (
                                <p>Зафиксированных изменений пока нет.</p>
                            ) : null}
                            {history?.map((change) => (
                                <article
                                    key={change.id}
                                    className="monitoring-wizard__preview"
                                >
                                    <strong>
                                        {new Date(change.created_at).toLocaleString(
                                            'ru-RU',
                                        )}
                                    </strong>
                                    <ul>
                                        {Object.entries(change.changes).map(
                                            ([field, values]) => (
                                                <li key={field}>
                                                    {labels[field] ?? field}:{' '}
                                                    {field === 'deadline_at'
                                                        ? new Date(
                                                              values.before,
                                                          ).toLocaleString('ru-RU')
                                                        : values.before}{' '}
                                                    →{' '}
                                                    {field === 'deadline_at'
                                                        ? new Date(
                                                              values.after,
                                                          ).toLocaleString('ru-RU')
                                                        : values.after}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </article>
                            ))}
                        </BottomSheet>{' '}
                    </div>,
                    document.body,
                )}
        </>
    );
}
