import { scopedUrl } from '../lib/workspace';
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
import {
    WorkspacePicker,
    AssigneeSelect,
    type TeamScope,
} from '../Components/WorkspacePicker';
import {
    ChecklistTemplates,
    type ChecklistTemplate,
} from '../Components/ChecklistTemplates';
import { ParticipationEconomics } from '../Components/ParticipationEconomics';
import {
    ParticipationComments,
    type ParticipationComment,
} from '../Components/ParticipationComments';
import type { PageProps } from '../types';

type ItemDraft = Omit<ChecklistItem, 'id'>;

export default function TenderWork() {
    const {
        tender,
        participation: initial,
        team,
        members,
        can_edit,
        templates,
        comments,
    } = usePage<
        PageProps<
            TeamScope & {
                templates: ChecklistTemplate[];
                tender: {
                    id: number;
                    title: string;
                    canonical_url: string;
                    deadline_at: string | null;
                };
                participation: Participation | null;
                comments: ParticipationComment[];
            }
        >
    >().props;
    const [participation, setParticipation] = useState(initial);
    const [stage, setStage] = useState<Stage>(initial?.stage ?? 'studying');
    const [assignee, setAssignee] = useState<number | null>(
        initial?.assignee_id ?? null,
    );
    const [taskAssignee, setTaskAssignee] = useState<number | null>(null);
    const [reminder, setReminder] = useState(false);
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
            }>({ method, url: scopedUrl(root + path, team), data });
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
            assignee_id: assignee,
            loss_reason: stage === 'lost' ? reason : null,
            version: stageVersion,
        });
    };
    const addItem = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        if (
            await mutate('post', '/checklist', {
                title,
                due_on: dueOn || null,
                assignee_id: taskAssignee,
                reminder_enabled: reminder,
            })
        ) {
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
                backHref={scopedUrl('/participation', team)}
                className="work-page"
                wide
            >
                <TenderWorkNav active="/participation" />
                <WorkspacePicker path={`${root}/work`} />
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
                                        scopedUrl(`${root}/work`, team),
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
                            {team && (
                                <p>
                                    Ответственный:{' '}
                                    {members.find(
                                        (m) => m.id === participation?.assignee_id,
                                    )?.name ?? 'Не назначен'}
                                </p>
                            )}
                            <p>
                                Это ваша оценка хода работы. Подача заявки выполняется
                                на площадке закупки.
                            </p>
                            <form className="work-form" onSubmit={saveStage}>
                                {team && (
                                    <AssigneeSelect
                                        label="Ответственный за заявку"
                                        value={assignee}
                                        onChange={setAssignee}
                                        members={members}
                                        disabled={busy || !can_edit}
                                    />
                                )}
                                <label>
                                    Этап
                                    <select
                                        value={stage}
                                        onChange={(e) =>
                                            setStage(e.target.value as Stage)
                                        }
                                        disabled={busy || !can_edit}
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
                                            disabled={busy || !can_edit}
                                        />
                                    </label>
                                ) : null}
                                <Button type="submit" disabled={busy || !can_edit}>
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
                                                busy={busy || !can_edit}
                                                members={members}
                                                team={team}
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
                                                disabled={busy || !can_edit}
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
                                                disabled={busy || !can_edit}
                                            />
                                        </label>
                                        {team && (
                                            <AssigneeSelect
                                                value={taskAssignee}
                                                onChange={setTaskAssignee}
                                                members={members}
                                                disabled={busy || !can_edit}
                                            />
                                        )}
                                        <label className="work-toggle">
                                            <input
                                                type="checkbox"
                                                checked={reminder}
                                                disabled={busy || !can_edit}
                                                onChange={(e) =>
                                                    setReminder(e.target.checked)
                                                }
                                            />
                                            Напомнить в Telegram
                                        </label>
                                        <p className="work-help">
                                            Один раз за день до срока и один раз при
                                            просрочке, после 09:00 по часовому поясу{' '}
                                            {team ? 'исполнителя' : 'профиля'}. Нужны
                                            срок, Telegram-вход и активный доступ.
                                            {team
                                                ? ' Назначьте исполнителя задачи.'
                                                : ''}
                                        </p>
                                        <Button
                                            type="submit"
                                            disabled={
                                                !can_edit ||
                                                busy ||
                                                participation.items.length >= 100
                                            }
                                        >
                                            Добавить задачу
                                        </Button>
                                    </form>
                                    <Link href={scopedUrl('/calendar', team)}>
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
                        <GlassCard className="work-card">
                            <ChecklistTemplates
                                initial={templates}
                                titles={participation?.items.map((i) => i.title) ?? []}
                                team={team}
                                disabled={!can_edit || busy}
                                started={!!participation}
                                apply={(id) => mutate('post', `/templates/${id}`, {})}
                            />
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
                {participation ? (
                    <ParticipationEconomics
                        initial={participation.economics}
                        root={root}
                        team={team}
                        canEdit={can_edit}
                    />
                ) : null}
                {participation ? (
                    <ParticipationComments
                        initial={comments}
                        root={root}
                        team={team}
                        members={members}
                        canEdit={can_edit}
                    />
                ) : null}
            </AppShell>
        </>
    );
}

function TaskEditor({
    item,
    members,
    team,
    busy,
    save,
    remove,
}: {
    item: ChecklistItem;
    members: TeamScope['members'];
    team: TeamScope['team'];
    busy: boolean;
    save: (data: ItemDraft) => Promise<boolean>;
    remove: () => Promise<boolean>;
}) {
    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(item.title);
    const [due, setDue] = useState(item.due_on ?? '');
    const [assignee, setAssignee] = useState(item.assignee_id);
    const [reminder, setReminder] = useState(item.reminder_enabled);
    const submit = async (e: FormEvent): Promise<void> => {
        e.preventDefault();
        if (
            await save({
                title,
                assignee_id: assignee,
                reminder_enabled: reminder,
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
                            assignee_id: item.assignee_id,
                            reminder_enabled: item.reminder_enabled,
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
            {team && (
                <p>
                    Исполнитель:{' '}
                    {members.find((m) => m.id === item.assignee_id)?.name ??
                        'Не назначен'}
                </p>
            )}
            <p>
                Telegram-напоминания: {item.reminder_enabled ? 'включены' : 'выключены'}
            </p>
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
                    {team && (
                        <AssigneeSelect
                            value={assignee}
                            onChange={setAssignee}
                            members={members}
                            disabled={busy}
                        />
                    )}
                    <label className="work-toggle">
                        <input
                            type="checkbox"
                            checked={reminder}
                            disabled={busy}
                            onChange={(e) => setReminder(e.target.checked)}
                        />
                        Напомнить в Telegram
                    </label>
                    <div className="work-actions">
                        <Button type="submit" disabled={busy} size="sm">
                            Сохранить задачу
                        </Button>
                        <Button
                            onClick={() => {
                                setTitle(item.title);
                                setDue(item.due_on ?? '');
                                setAssignee(item.assignee_id);
                                setReminder(item.reminder_enabled);
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
