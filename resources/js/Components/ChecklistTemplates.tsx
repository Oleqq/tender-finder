import { scopedUrl } from '../lib/workspace';
import axios from 'axios';
import { useState } from 'react';
import { Button } from './ui';
import { type TeamScope } from './WorkspacePicker';
export type ChecklistTemplate = { id: number; name: string; items: string[] };
export function ChecklistTemplates({
    initial,
    titles,
    team,
    disabled,
    started,
    apply,
}: {
    initial: ChecklistTemplate[];
    titles: string[];
    team: TeamScope['team'];
    disabled: boolean;
    started: boolean;
    apply: (id: number) => Promise<boolean>;
}) {
    const [templates, setTemplates] = useState(initial);
    const [name, setName] = useState('');
    const [items, setItems] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    return (
        <div className="work-form">
            <h3>Шаблоны чек-листов</h3>
            <p className="work-help">
                Сохраняются названия задач. Сроки, исполнители и напоминания задаются в
                каждой заявке. Повторное применение того же шаблона не дублирует задачи.
            </p>
            {error && (
                <p role="alert" className="work-error">
                    {error}
                </p>
            )}
            {notice && <p role="status">{notice}</p>}
            {templates.map((t) => (
                <div key={t.id} className="work-task">
                    <strong>{t.name}</strong>
                    <p>{t.items.length} задач</p>
                    <div className="work-actions">
                        <Button
                            disabled={disabled || busy || !started}
                            variant="secondary"
                            onClick={async () => {
                                setBusy(true);
                                setNotice('');
                                try {
                                    if (await apply(t.id))
                                        setNotice(
                                            'Шаблон применён. Если он уже применялся, задачи не дублируются.',
                                        );
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Применить «{t.name}»
                        </Button>
                        <Button
                            disabled={disabled || busy}
                            variant="ghost"
                            onClick={async () => {
                                if (
                                    !window.confirm(
                                        'Удалить шаблон? Задачи в заявках сохранятся.',
                                    )
                                )
                                    return;
                                setBusy(true);
                                setError('');
                                try {
                                    await window.axios.delete(
                                        scopedUrl(`/checklist-templates/${t.id}`, team),
                                    );
                                    setTemplates((old) =>
                                        old.filter((x) => x.id !== t.id),
                                    );
                                } catch {
                                    setError('Не удалось удалить шаблон.');
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            Удалить шаблон
                        </Button>
                    </div>
                </div>
            ))}
            {!disabled && (
                <form
                    className="work-form"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        setBusy(true);
                        setError('');
                        try {
                            const r = await window.axios.post(
                                scopedUrl('/checklist-templates', team),
                                {
                                    name,
                                    items: items
                                        .split('\n')
                                        .map((s) => s.trim())
                                        .filter(Boolean),
                                },
                            );
                            setTemplates((old) => [...old, r.data.template]);
                            setName('');
                            setItems('');
                            setNotice('Шаблон сохранён.');
                        } catch (e) {
                            setError(
                                axios.isAxiosError(e)
                                    ? (e.response?.data.message ??
                                          'Не удалось сохранить шаблон.')
                                    : 'Проверьте соединение.',
                            );
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    <label>
                        Название шаблона
                        <input
                            required
                            maxLength={120}
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            disabled={busy}
                        />
                    </label>
                    <label>
                        Задачи — по одной в строке
                        <textarea
                            required
                            value={items}
                            onChange={(e) => setItems(e.target.value)}
                            disabled={busy}
                        />
                    </label>
                    <Button
                        variant="ghost"
                        disabled={busy || titles.length === 0}
                        onClick={() => setItems(titles.join('\n'))}
                    >
                        Взять задачи из этой заявки
                    </Button>
                    <Button type="submit" disabled={busy}>
                        Сохранить шаблон
                    </Button>
                </form>
            )}
        </div>
    );
}
