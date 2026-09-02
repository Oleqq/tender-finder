import { useEffect, useState } from 'react';
import { Button, FieldError } from './ui';
import { downloadTenderExport, type TenderExportFormat } from '../lib/tenderExport';

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
    const [exporting, setExporting] = useState('');
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

    const exportNew = async (
        run: SavedSearchRun,
        format: TenderExportFormat,
    ): Promise<void> => {
        const key = `${run.id}-${format}`;
        setExporting(key);
        setError('');

        try {
            await downloadTenderExport({
                format,
                scope: 'run_new',
                query_id: queryId,
                run_id: run.id,
                filter_summary: 'Только новые относительно предыдущего запуска',
            });
        } catch {
            setError('Не удалось выгрузить новые карточки выбранного запуска.');
        } finally {
            setExporting('');
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
            {runs.length > 0 ? <RunStatistics runs={runs} /> : null}
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
                    <div className="saved-run-history__actions">
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
                        <Button
                            disabled={exporting !== '' || run.new_count === 0}
                            onClick={() => exportNew(run, 'csv')}
                            size="sm"
                            variant="ghost"
                        >
                            {exporting === `${run.id}-csv` ? 'CSV…' : 'Новые CSV'}
                        </Button>
                        <Button
                            disabled={exporting !== '' || run.new_count === 0}
                            onClick={() => exportNew(run, 'xlsx')}
                            size="sm"
                            variant="ghost"
                        >
                            {exporting === `${run.id}-xlsx` ? 'XLSX…' : 'Новые XLSX'}
                        </Button>
                    </div>
                </div>
            ))}
            {error ? <FieldError>{error}</FieldError> : null}
        </div>
    );
}

function RunStatistics({ runs }: { runs: SavedSearchRun[] }) {
    const chronological = [...runs].reverse();
    const totalMatched = runs.reduce((sum, run) => sum + run.items_matched, 0);
    const totalNew = runs.reduce((sum, run) => sum + run.new_count, 0);
    const partialRuns = runs.filter((run) => run.partially_loaded).length;
    const peak = Math.max(
        1,
        ...chronological.flatMap((run) => [run.items_matched, run.new_count]),
    );

    return (
        <section className="run-statistics" aria-label="Статистика мониторинга">
            <dl className="run-statistics__metrics">
                <div>
                    <dt>Запусков</dt>
                    <dd>{runs.length}</dd>
                </div>
                <div>
                    <dt>Среднее</dt>
                    <dd>{Math.round(totalMatched / runs.length)}</dd>
                </div>
                <div>
                    <dt>Новых всего</dt>
                    <dd>{totalNew}</dd>
                </div>
                <div>
                    <dt>Неполных</dt>
                    <dd>{partialRuns}</dd>
                </div>
            </dl>
            <div
                aria-label="Динамика совпадений и новых карточек"
                className="run-statistics__chart"
                role="img"
            >
                {chronological.map((run) => (
                    <div className="run-statistics__day" key={run.id}>
                        <span
                            aria-label={'Найдено: ' + run.items_matched}
                            className="is-matched"
                            style={{ height: barHeight(run.items_matched, peak) }}
                        />
                        <span
                            aria-label={'Новых: ' + run.new_count}
                            className="is-new"
                            style={{ height: barHeight(run.new_count, peak) }}
                        />
                    </div>
                ))}
            </div>
            <p className="run-statistics__legend">
                <span>
                    <i className="is-matched" /> Найдено
                </span>
                <span>
                    <i className="is-new" /> Новые
                </span>
            </p>
        </section>
    );
}

function barHeight(value: number, peak: number): string {
    return value === 0 ? '2px' : Math.max(8, (value / peak) * 64) + 'px';
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
