import { scopedUrl } from '../lib/workspace';
import axios from 'axios';
import { useState } from 'react';
import { Button } from './ui';
import { type TeamScope } from './WorkspacePicker';
export type ChecklistTemplate = {
    id: number;
    name: string;
    items: string[];
    version: number;
    versions: {
        id: number;
        version: number;
        name: string;
        items: string[];
        created_at: string;
    }[];
};
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
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editName, setEditName] = useState('');
    const [editItems, setEditItems] = useState('');
    return (
        <div className="work-form">
            <h3>Шаблоны чек-листов</h3>
            <p className="work-help">
                Сохраняются названия задач. Сроки, исполнители и напоминания задаются в
                каждой заявке. Каждую версию можно применить к заявке один раз.
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
                    <p>
                        {t.items.length} задач · версия {t.version}
                    </p>
                    {t.versions.length > 1 && (
                        <details>
                            <summary>История версий</summary>
                            <div className="work-list">
                                {t.versions.map((past) => (
                                    <div className="work-task" key={past.id}>
                                        <strong>
                                            Версия {past.version} · {past.name}
                                        </strong>
                                        <p>{past.items.length} задач</p>
                                        {past.version !== t.version && (
                                            <Button
                                                disabled={disabled || busy}
                                                variant="ghost"
                                                onClick={async () => {
                                                    setBusy(true);
                                                    setError('');
                                                    try {
                                                        const response =
                                                            await window.axios.patch(
                                                                scopedUrl(
                                                                    `/checklist-templates/${t.id}`,
                                                                    team,
                                                                ),
                                                                {
                                                                    name: past.name,
                                                                    items: past.items,
                                                                    version: t.version,
                                                                },
                                                            );
                                                        setTemplates((old) =>
                                                            old.map((item) =>
                                                                item.id === t.id
                                                                    ? response.data
                                                                          .template
                                                                    : item,
                                                            ),
                                                        );
                                                        setNotice(
                                                            `Версия ${past.version} восстановлена как новая.`,
                                                        );
                                                    } catch (e) {
                                                        setError(
                                                            axios.isAxiosError(e)
                                                                ? (e.response?.data
                                                                      .message ??
                                                                      'Не удалось восстановить версию.')
                                                                : 'Проверьте соединение.',
                                                        );
                                                    } finally {
                                                        setBusy(false);
                                                    }
                                                }}
                                            >
                                                Восстановить как новую
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </details>
                    )}
                    {editingId === t.id && (
                        <form
                            className="work-form"
                            onSubmit={async (event) => {
                                event.preventDefault();
                                setBusy(true);
                                setError('');
                                try {
                                    const response = await window.axios.patch(
                                        scopedUrl(`/checklist-templates/${t.id}`, team),
                                        {
                                            name: editName,
                                            items: editItems
                                                .split('\n')
                                                .map((item) => item.trim())
                                                .filter(Boolean),
                                            version: t.version,
                                        },
                                    );
                                    setTemplates((old) =>
                                        old.map((item) =>
                                            item.id === t.id
                                                ? response.data.template
                                                : item,
                                        ),
                                    );
                                    setEditingId(null);
                                    setNotice('Новая версия шаблона сохранена.');
                                } catch (e) {
                                    setError(
                                        axios.isAxiosError(e)
                                            ? (e.response?.data.message ??
                                                  'Не удалось обновить шаблон.')
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
                                    value={editName}
                                    onChange={(e) => setEditName(e.target.value)}
                                />
                            </label>
                            <label>
                                Задачи — по одной в строке
                                <textarea
                                    required
                                    value={editItems}
                                    onChange={(e) => setEditItems(e.target.value)}
                                />
                            </label>
                            <div className="work-actions">
                                <Button type="submit" disabled={busy}>
                                    Сохранить версию
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => setEditingId(null)}
                                >
                                    Отмена
                                </Button>
                            </div>
                        </form>
                    )}
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
                                            'Версия шаблона применена. Повторно она задачи не дублирует.',
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
                            variant="secondary"
                            onClick={() => {
                                setEditingId(t.id);
                                setEditName(t.name);
                                setEditItems(t.items.join('\n'));
                                setNotice('');
                            }}
                        >
                            Редактировать
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
