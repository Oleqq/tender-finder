import { useEffect, useState } from 'react';
import { Button, FieldError } from './ui';

export type SavedSearchRun = {
    id: number;
    created_at: string;
    items_seen: number;
    items_matched: number;
    items_created: number;
    new_count: number;
    pages_requested: number;
    pages_loaded: number;
    partially_loaded: boolean;
};

export type SavedSearchRunResult<TTender> = {
    run: SavedSearchRun;
    only_new: boolean;
    tenders: TTender[];
};

export function SavedSearchRunHistory<TTender>({
    queryId,
    onOpenRun,
}: {
    queryId: number;
    onOpenRun: (result: SavedSearchRunResult<TTender>) => void;
}) {
    const [runs, setRuns] = useState<SavedSearchRun[]>([]);
    const [onlyNew, setOnlyNew] = useState(false);
    const [loading, setLoading] = useState(true);
    const [openingId, setOpeningId] = useState<number | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        let active = true;

        window.axios
            .get<{ runs: SavedSearchRun[] }>(`/queries/${queryId}/runs`)
            .then((response) => {
                if (active) {
                    setRuns(response.data.runs);
                    setError('');
                }
            })
            .catch(() => {
                if (active) {
                    setError('Не удалось загрузить историю запусков.');
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });

        return () => {
            active = false;
        };
    }, [queryId]);

    const openRun = async (run: SavedSearchRun): Promise<void> => {
        setOpeningId(run.id);
        setError('');

        try {
            const response = await window.axios.get<SavedSearchRunResult<TTender>>(
                `/queries/${queryId}/runs/${run.id}`,
                { params: { only_new: onlyNew ? 1 : 0 } },
            );
            onOpenRun(response.data);
        } catch {
            setError('Не удалось открыть выбранный запуск.');
        } finally {
            setOpeningId(null);
        }
    };

    return (
        <div className="saved-run-history">
            <div className="saved-run-history__heading">
                <strong>История запусков</strong>
                <label>
                    <input
                        checked={onlyNew}
                        onChange={(event) => setOnlyNew(event.target.checked)}
                        type="checkbox"
                    />
                    Только новые относительно прошлого запуска
                </label>
            </div>
            {loading ? <p>Загружаем историю…</p> : null}
            {!loading && runs.length === 0 ? <p>Запусков пока нет.</p> : null}
            {runs.map((run) => (
                <div className="saved-run-history__row" key={run.id}>
                    <div>
                        <strong>{formatDate(run.created_at)}</strong>
                        <span>
                            Найдено {run.items_matched} · новых относительно прошлого{' '}
                            {run.new_count} · страниц {run.pages_loaded}/
                            {run.pages_requested}
                        </span>
                    </div>
                    <Button
                        disabled={openingId !== null}
                        onClick={() => openRun(run)}
                        size="sm"
                        variant="secondary"
                    >
                        {openingId === run.id
                            ? 'Открываем…'
                            : onlyNew
                              ? 'Показать новые'
                              : 'Показать'}
                    </Button>
                </div>
            ))}
            {error ? <FieldError>{error}</FieldError> : null}
        </div>
    );
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
