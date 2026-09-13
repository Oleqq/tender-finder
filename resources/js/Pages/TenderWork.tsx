import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState, type FormEvent } from 'react';
import { AppShell } from '../Components/AppShell';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import { Badge, Button, GlassCard } from '../Components/ui';
import {
    stages,
    stageLabel,
    type Stage,
    type Participation,
    type ChecklistItem,
} from '../lib/participation';
import type { PageProps } from '../types';

type ItemDraft = Pick<ChecklistItem, 'title' | 'due_on' | 'completed' | 'version'>;

export default function TenderWork() {
    const { tender, participation: initial } = usePage<
        PageProps<{
            tender: {
                id: number;
                title: string;
                canonical_url: string;
                deadline_at: string | null;
            };
            participation: Participation | null;
        }>
    >().props;
    const [participation, setParticipation] = useState(initial);
    const [stage, setStage] = useState<Stage>(initial?.stage ?? 'studying');
    const [stageVersion, setStageVersion] = useState(initial?.version ?? 0);
    const [reason, setReason] = useState(initial?.loss_reason ?? '');
    const [title, setTitle] = useState('');
    const [dueOn, setDueOn] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [conflict, setConflict] = useState(false);
    const root = `/tenders/${tender.id}`;

    const mutate = async (
        method: 'put' | 'post' | 'patch' | 'delete',
        path: string,
        data: unknown,
    ): Promise<boolean> => {
        setBusy(true);
        setError('');
        setNotice('');
        setConflict(false);
        try {
            const response = await window.axios.request<{
                participation: Participation;
            }>({ method, url: root + path, data });
            setParticipation(response.data.participation);
            // Checklist responses may contain another tab's stage. Keep the
            // version belonging to this form until its own save or reload.
            if (method === 'put') {
                setStageVersion(response.data.participation.version);
            }
            setNotice('Изменения сохранены.');
            return true;
        } catch (e) {
            if (
                axios.isAxiosError<{
                    message?: string;
                    errors?: Record<string, string[]>;
                }>(e)
            ) {
                setConflict(e.response?.status === 409);
                setError(
                    Object.values(e.response?.data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        e.response?.data.message ||
                        'Не удалось сохранить. Попробуйте ещё раз.',
                );
            } else {
                setError('Не удалось сохранить. Проверьте соединение.');
            }
            return false;
        } finally {
            setBusy(false);
        }
    };

    const saveStage = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        await mutate('put', '/participation', {
            stage,
            loss_reason: stage === 'lost' ? reason : null,
            version: stageVersion,
        });
    };
    const addItem = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        if (await mutate('post', '/checklist', { title, due_on: dueOn || null })) {
            setTitle('');
            setDueOn('');
        }
    };
    const completed = participation?.items.filter((item) => item.completed).length ?? 0;

    return (
        <>
            <Head title="Работа над заявкой" />
            <AppShell
                title="Заявка"
                activeNav="/tenders"
                backHref="/participation"
                className="work-page"
                wide
            >
                <TenderWorkNav active="/participation" />
                <GlassCard tone="accent" className="work-card">
                    <Badge>
                        {participation
                            ? stageLabel(participation.stage)
                            : 'Ещё не в работе'}
                    </Badge>
                    <h2>{tender.title}</h2>
                    <p>
                        {tender.deadline_at
                            ? `Срок подачи: ${new Date(tender.deadline_at).toLocaleString('ru-RU')}`
                            : 'Срок подачи не указан в источнике'}
                    </p>
                    <a
                        className="button button--secondary"
                        href={tender.canonical_url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Проверить первоисточник
                    </a>
                </GlassCard>
                <div aria-live="polite">
                    {notice ? <p className="work-success">{notice}</p> : null}
                </div>
                {error ? (
                    <div className="work-error" role="alert">
                        <p>{error}</p>
                        {conflict ? (
                            <Button
                                onClick={() =>
                                    router.get(
                                        `${root}/work`,
                                        {},
                                        { preserveState: false, preserveScroll: true },
                                    )
                                }
                                variant="secondary"
                            >
                                Обновить данные
                            </Button>
                        ) : null}
                    </div>
                ) : null}
                <div className="work-detail-grid">
                    <div className="work-list">
                        <GlassCard className="work-card">
                            <h2>Этап участия</h2>
                            <p>
                                Это ваша оценка хода работы. Подача заявки выполняется
                                на площадке закупки.
                            </p>
                            <form className="work-form" onSubmit={saveStage}>
                                <label>
                                    Этап
                                    <select
                                        value={stage}
                                        onChange={(e) =>
                                            setStage(e.target.value as Stage)
                                        }
                                        disabled={busy}
                                    >
                                        {stages.map((s) => (
                                            <option key={s.value} value={s.value}>
                                                {s.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                {stage === 'lost' ? (
                                    <label>
                                        Причина проигрыша
                                        <textarea
                                            required
                                            maxLength={2000}
                                            value={reason}
                                            onChange={(e) => setReason(e.target.value)}
                                            placeholder="Например, предложение конкурента оказалось дешевле"
                                            disabled={busy}
                                        />
                                    </label>
                                ) : null}
                                <Button type="submit" disabled={busy}>
                                    {busy
                                        ? 'Сохраняем…'
                                        : participation
                                          ? 'Сохранить этап'
                                          : 'Начать участие'}
                                </Button>
                            </form>
                        </GlassCard>
                        <GlassCard className="work-card">
                            <h2>Чек-лист подготовки</h2>
                            {participation ? (
                                <>
                                    <div className="work-progress">
                                        <progress
                                            max={Math.max(
                                                participation.items.length,
                                                1,
                                            )}
                                            value={completed}
                                            aria-label="Выполненные задачи"
                                        />
                                        <span>
                                            {completed} / {participation.items.length}
                                        </span>
                                    </div>
                                    {participation.items.length === 0 ? (
                                        <p>
                                            Добавьте задачи: проверить требования,
                                            подготовить документы, согласовать цену.
                                        </p>
                                    ) : null}
                                    <div className="work-list">
                                        {participation.items.map((item) => (
                                            <TaskEditor
                                                key={`${item.id}-${item.version}`}
                                                item={item}
                                                busy={busy}
                                                save={(data) =>
                                                    mutate(
                                                        'patch',
                                                        `/checklist/${item.id}`,
                                                        data,
                                                    )
                                                }
                                                remove={() =>
                                                    mutate(
                                                        'delete',
                                                        `/checklist/${item.id}`,
                                                        { version: item.version },
                                                    )
                                                }
                                            />
                                        ))}
                                    </div>
                                    <form
                                        className="work-form work-new-task"
                                        onSubmit={addItem}
                                    >
                                        <label>
                                            Новая задача
                                            <input
                                                required
                                                maxLength={240}
                                                value={title}
                                                onChange={(e) =>
                                                    setTitle(e.target.value)
                                                }
                                                placeholder="Подготовить техническое предложение"
                                                disabled={busy}
                                            />
                                        </label>
                                        <label>
                                            Срок задачи — необязательно
                                            <input
                                                type="date"
                                                value={dueOn}
                                                onChange={(e) =>
                                                    setDueOn(e.target.value)
                                                }
                                                disabled={busy}
                                            />
                                        </label>
                                        <Button
                                            type="submit"
                                            disabled={
                                                busy ||
                                                participation.items.length >= 100
                                            }
                                        >
                                            Добавить задачу
                                        </Button>
                                    </form>
                                    <Link href="/calendar">
                                        Посмотреть сроки в календаре
                                    </Link>
                                </>
                            ) : (
                                <p>
                                    Нажмите «Начать участие», чтобы составить чек-лист
                                    этой закупки.
                                </p>
                            )}
                        </GlassCard>
                    </div>
                    <GlassCard className="work-card work-history">
                        <h2>История участия</h2>
                        {!participation?.history.length ? (
                            <p>Здесь появятся изменения этапов и причины проигрыша.</p>
                        ) : (
                            <ol>
                                {participation.history.map((event) => (
                                    <li key={event.id}>
                                        <strong>
                                            {stageLabel(event.from_stage)} →{' '}
                                            {stageLabel(event.to_stage)}
                                        </strong>
                                        <time dateTime={event.created_at}>
                                            {new Date(event.created_at).toLocaleString(
                                                'ru-RU',
                                            )}
                                        </time>
                                        {event.reason ? <p>{event.reason}</p> : null}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </GlassCard>
                </div>
            </AppShell>
        </>
    );
}

function TaskEditor({
    item,
    busy,
    save,
    remove,
}: {
    item: ChecklistItem;
    busy: boolean;
    save: (data: ItemDraft) => Promise<boolean>;
    remove: () => Promise<boolean>;
}) {
    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(item.title);
    const [due, setDue] = useState(item.due_on ?? '');
    const submit = async (e: FormEvent): Promise<void> => {
        e.preventDefault();
        if (
            await save({
                title,
                due_on: due || null,
                completed: item.completed,
                version: item.version,
            })
        )
            setEditing(false);
    };
    return (
        <div className={`work-task ${item.completed ? 'is-complete' : ''}`}>
            <label className="work-task-check">
                <input
                    type="checkbox"
                    checked={item.completed}
                    disabled={busy}
                    onChange={(e) =>
                        void save({
                            title: item.title,
                            due_on: item.due_on,
                            completed: e.target.checked,
                            version: item.version,
                        })
                    }
                />
                <span>{item.title}</span>
            </label>
            {item.due_on ? (
                <p>Срок: {item.due_on.split('-').reverse().join('.')}</p>
            ) : null}
            {editing ? (
                <form onSubmit={submit} className="work-form">
                    <label>
                        Название задачи
                        <input
                            required
                            maxLength={240}
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            disabled={busy}
                        />
                    </label>
                    <label>
                        Срок
                        <input
                            type="date"
                            value={due}
                            onChange={(e) => setDue(e.target.value)}
                            disabled={busy}
                        />
                    </label>
                    <div className="work-actions">
                        <Button type="submit" disabled={busy} size="sm">
                            Сохранить задачу
                        </Button>
                        <Button
                            onClick={() => {
                                setTitle(item.title);
                                setDue(item.due_on ?? '');
                                setEditing(false);
                            }}
                            disabled={busy}
                            variant="ghost"
                            size="sm"
                        >
                            Отмена
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="work-actions">
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={() => setEditing(true)}
                    >
                        Изменить
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={() => {
                            if (window.confirm(`Удалить задачу «${item.title}»?`))
                                void remove();
                        }}
                    >
                        Удалить
                    </Button>
                </div>
            )}
        </div>
    );
}
