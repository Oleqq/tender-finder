import axios from 'axios';
import { useRef, useState } from 'react';
import { Button, InlineAlert } from './ui';
import type { RepeatSnapshot } from '../lib/repeatTenders';

export function RepeatTenderLookup({
    onSelect,
    disabled = false,
}: {
    onSelect: (value: RepeatSnapshot) => void;
    disabled?: boolean;
}) {
    const [search, setSearch] = useState('');
    const [rows, setRows] = useState<RepeatSnapshot[] | null>(null);
    const [limited, setLimited] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const lock = useRef(false);
    async function lookup() {
        if (lock.current) return;
        lock.current = true;
        setBusy(true);
        setError('');
        setRows(null);
        try {
            const { data } = await axios.get<{
                tenders: RepeatSnapshot[];
                limited: boolean;
            }>('/repeat-tenders/lookup', { params: { search } });
            setRows(data.tenders);
            setLimited(data.limited);
        } catch {
            setError(
                'Не удалось найти карточки. Проверьте доступ и попробуйте ещё раз.',
            );
        } finally {
            setBusy(false);
            lock.current = false;
        }
    }
    return (
        <div className="proposal-form">
            <label>
                Название или точный номер своей закупки
                <input
                    aria-label="Название или точный номер своей закупки"
                    disabled={busy || disabled}
                    maxLength={100}
                    minLength={2}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </label>
            <Button
                variant="secondary"
                disabled={busy || disabled || search.trim().length < 2}
                onClick={() => void lookup()}
            >
                Найти в моей ленте
            </Button>
            <p>
                По названию проверяются 500 последних доступных карточек; точный номер
                извещения ищется отдельно. До 20 результатов.
            </p>
            {limited ? (
                <p>
                    Выборка ограничена. Уточните номер или откройте нужную карточку из
                    ленты.
                </p>
            ) : null}
            {error ? (
                <InlineAlert tone="danger" title="Поиск не выполнен">
                    {error}
                </InlineAlert>
            ) : null}
            {rows?.length === 0 ? <p>В доступной выборке ничего не найдено.</p> : null}
            <div className="repeat-results">
                {rows?.map((row) => (
                    <div key={row.id} className="repeat-result">
                        <strong>{row.title}</strong>
                        <small>
                            {row.reg_number || 'Номер не передан'} ·{' '}
                            {row.customer || 'Заказчик неизвестен'}
                        </small>
                        <Button
                            variant="ghost"
                            disabled={busy || disabled}
                            onClick={() => {
                                onSelect(row);
                                setRows(null);
                            }}
                        >
                            Выбрать закупку {row.id}
                        </Button>
                    </div>
                ))}
            </div>
        </div>
    );
}
