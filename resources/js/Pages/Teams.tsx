import { scopedUrl } from '../lib/workspace';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Button, GlassCard } from '../Components/ui';
import { WorkspacePicker, type TeamScope } from '../Components/WorkspacePicker';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import type { PageProps } from '../types';

type TeamActivity = {
    id: number;
    action: string;
    context: Record<string, string | number | boolean | null>;
    created_at: string;
    actor_name: string | null;
};

const activityLabel = (activity: TeamActivity): string => {
    const labels: Record<string, string> = {
        team_created: 'создал команду',
        invitation_created: 'создал приглашение',
        invitation_revoked: 'отозвал приглашение',
        member_joined: 'присоединился к команде',
        invitation_accepted: 'использовал приглашение, уже состоя в команде',
        member_left: 'покинул команду',
        member_removed: 'удалил участника',
        member_role_changed: 'изменил роль участника',
        ownership_transferred: 'передал владение командой',
        team_archived: 'перенёс команду в архив',
        team_restored: 'восстановил команду из архива',
        template_created: 'создал шаблон',
        template_updated: 'обновил шаблон',
        template_deleted: 'удалил шаблон',
        template_applied: 'применил шаблон к заявке',
        participation_updated: 'изменил этап заявки',
        comment_created: 'добавил комментарий к заявке',
        comment_deleted: 'удалил комментарий из заявки',
        economics_updated: 'обновил экономику заявки',
        task_created: 'создал задачу',
        task_updated: 'изменил задачу',
        task_deleted: 'удалил задачу',
    };
    const template = activity.context.template_name
        ? ` «${activity.context.template_name}»`
        : '';

    return `${labels[activity.action] ?? activity.action}${template}`;
};

export default function Teams() {
    const { team, members, invitations, activities, auth } = usePage<
        PageProps<
            TeamScope & {
                invitations: { id: number; role: string; expires_at: string }[];
                activities: TeamActivity[];
            }
        >
    >().props;
    const [name, setName] = useState('');
    const [role, setRole] = useState('member');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [invite, setInvite] = useState('');
    const [deleteName, setDeleteName] = useState('');
    const mutate = async (
        method: 'post' | 'patch' | 'delete',
        url: string,
        data: unknown = {},
    ) => {
        setBusy(true);
        setError('');
        try {
            const response = await window.axios.request({ method, url, data });
            if (response.data.url) {
                setInvite(response.data.url);
                router.reload();
            } else if (response.data.deleted) router.get('/teams');
            else if (response.data.team_id)
                router.get('/teams', { team_id: response.data.team_id });
            else router.get('/teams', team ? { team_id: team.id } : {});
        } catch (e) {
            setError(
                axios.isAxiosError(e)
                    ? (e.response?.data.message ?? 'Не удалось сохранить.')
                    : 'Проверьте соединение.',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <Head title="Команды" />
            <AppShell title="Команды" activeNav="/tenders" className="work-page">
                <TenderWorkNav active="/teams" />
                <WorkspacePicker path="/teams" />
                {error && (
                    <p role="alert" className="work-error">
                        {error}
                    </p>
                )}
                <GlassCard className="work-card">
                    <h2>Создать команду</h2>
                    <form
                        className="work-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void mutate('post', '/teams', { name });
                        }}
                    >
                        <label>
                            Название компании или команды
                            <input
                                required
                                maxLength={120}
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </label>
                        <Button type="submit" disabled={busy}>
                            Создать команду
                        </Button>
                    </form>
                </GlassCard>
                {team && (
                    <>
                        <GlassCard className="work-card">
                            <h2>{team.name}</h2>
                            {team.archived_at && (
                                <p role="status" className="work-help">
                                    Команда находится в архиве. Заявки, задачи и журнал
                                    сохранены, изменения отключены.
                                </p>
                            )}
                            <Link href={scopedUrl('/participation', team)}>
                                Заявки команды
                            </Link>
                            <p>
                                Владелец управляет приглашениями и ролями. Участник
                                ведёт заявки и задачи. Наблюдатель только просматривает.
                            </p>
                            <div className="work-list">
                                {members.map((m) => (
                                    <div key={m.id} className="work-task">
                                        <strong>{m.name || `Участник ${m.id}`}</strong>
                                        <p>
                                            {m.role === 'owner'
                                                ? 'Владелец'
                                                : m.role === 'viewer'
                                                  ? 'Наблюдатель'
                                                  : 'Участник'}
                                        </p>
                                        <p className="work-help">
                                            Активных заявок:{' '}
                                            {m.active_applications ?? 0} · открытых
                                            задач: {m.open_tasks ?? 0} · просрочено:{' '}
                                            {m.overdue_tasks ?? 0}
                                        </p>
                                        {team.role === 'owner' &&
                                            !team.archived_at &&
                                            m.role !== 'owner' && (
                                                <div className="work-actions">
                                                    <Button
                                                        disabled={busy}
                                                        variant="secondary"
                                                        onClick={() =>
                                                            void mutate(
                                                                'patch',
                                                                `/teams/${team.id}/members/${m.id}`,
                                                                {
                                                                    role:
                                                                        m.role ===
                                                                        'viewer'
                                                                            ? 'member'
                                                                            : 'viewer',
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {m.role === 'viewer'
                                                            ? 'Разрешить редактирование'
                                                            : 'Только просмотр'}
                                                    </Button>
                                                    <Button
                                                        disabled={busy}
                                                        variant="ghost"
                                                        onClick={() => {
                                                            if (
                                                                window.confirm(
                                                                    'Удалить участника? Его назначения будут сняты.',
                                                                )
                                                            )
                                                                void mutate(
                                                                    'delete',
                                                                    `/teams/${team.id}/members/${m.id}`,
                                                                );
                                                        }}
                                                    >
                                                        Удалить участника
                                                    </Button>
                                                    {m.role === 'member' && (
                                                        <Button
                                                            disabled={busy}
                                                            variant="ghost"
                                                            onClick={() => {
                                                                if (
                                                                    window.confirm(
                                                                        `Передать владение пользователю ${m.name || m.id}? Вы останетесь участником.`,
                                                                    )
                                                                )
                                                                    void mutate(
                                                                        'post',
                                                                        `/teams/${team.id}/transfer-ownership`,
                                                                        {
                                                                            member_id:
                                                                                m.id,
                                                                        },
                                                                    );
                                                            }}
                                                        >
                                                            Сделать владельцем
                                                        </Button>
                                                    )}
                                                </div>
                                            )}
                                        {m.id === auth.user?.id &&
                                            !team.archived_at &&
                                            m.role !== 'owner' && (
                                                <Button
                                                    disabled={busy}
                                                    variant="ghost"
                                                    onClick={async () => {
                                                        if (
                                                            !window.confirm(
                                                                'Покинуть команду?',
                                                            )
                                                        )
                                                            return;
                                                        setBusy(true);
                                                        try {
                                                            await window.axios.delete(
                                                                `/teams/${team.id}/members/${m.id}`,
                                                            );
                                                            router.get('/teams');
                                                        } catch {
                                                            setError(
                                                                'Не удалось покинуть команду.',
                                                            );
                                                            setBusy(false);
                                                        }
                                                    }}
                                                >
                                                    Покинуть команду
                                                </Button>
                                            )}
                                    </div>
                                ))}
                            </div>
                        </GlassCard>
                        {team.role === 'owner' && !team.archived_at && (
                            <GlassCard className="work-card">
                                <h2>Пригласить сотрудника</h2>
                                <p>
                                    Одноразовая ссылка действует 7 дней. Передайте её
                                    нужному сотруднику. Присоединение требует его
                                    подтверждения.
                                </p>
                                <label className="work-form">
                                    Роль
                                    <select
                                        value={role}
                                        onChange={(e) => setRole(e.target.value)}
                                    >
                                        <option value="member">Участник</option>
                                        <option value="viewer">Наблюдатель</option>
                                    </select>
                                </label>
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        void mutate(
                                            'post',
                                            `/teams/${team.id}/invitations`,
                                            { role },
                                        )
                                    }
                                >
                                    Создать приглашение
                                </Button>
                                {invite && (
                                    <label className="work-form">
                                        Ссылка приглашения
                                        <input
                                            readOnly
                                            value={invite}
                                            onFocus={(e) => e.target.select()}
                                        />
                                        <Button
                                            variant="secondary"
                                            onClick={() =>
                                                void navigator.clipboard
                                                    .writeText(invite)
                                                    .catch(() =>
                                                        setError(
                                                            'Выделите и скопируйте ссылку вручную.',
                                                        ),
                                                    )
                                            }
                                        >
                                            Скопировать ссылку
                                        </Button>
                                    </label>
                                )}
                                {invitations.map((i) => (
                                    <div className="work-actions" key={i.id}>
                                        <span>
                                            {i.role === 'viewer'
                                                ? 'Наблюдатель'
                                                : 'Участник'}{' '}
                                            · до{' '}
                                            {new Date(i.expires_at).toLocaleDateString(
                                                'ru-RU',
                                            )}
                                        </span>
                                        <Button
                                            disabled={busy}
                                            variant="ghost"
                                            onClick={() => {
                                                setInvite('');
                                                void mutate(
                                                    'delete',
                                                    `/teams/${team.id}/invitations/${i.id}`,
                                                );
                                            }}
                                        >
                                            Отозвать приглашение
                                        </Button>
                                    </div>
                                ))}
                            </GlassCard>
                        )}
                        {team.role === 'owner' && (
                            <GlassCard className="work-card">
                                <h2>Управление командой</h2>
                                {!team.archived_at ? (
                                    <>
                                        <p>
                                            Архив отключает изменения, приглашения и
                                            напоминания, сохраняя заявки и журнал.
                                        </p>
                                        <Button
                                            disabled={busy}
                                            variant="secondary"
                                            onClick={() => {
                                                if (
                                                    window.confirm(
                                                        'Перенести команду в архив?',
                                                    )
                                                )
                                                    void mutate(
                                                        'patch',
                                                        `/teams/${team.id}/archive`,
                                                        { archived: true },
                                                    );
                                            }}
                                        >
                                            Перенести в архив
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <Button
                                            disabled={busy}
                                            variant="secondary"
                                            onClick={() =>
                                                void mutate(
                                                    'patch',
                                                    `/teams/${team.id}/archive`,
                                                    { archived: false },
                                                )
                                            }
                                        >
                                            Восстановить команду
                                        </Button>
                                        <form
                                            className="work-form"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                if (
                                                    window.confirm(
                                                        'Окончательно удалить команду и все её общие заявки и задачи? Это действие нельзя отменить.',
                                                    )
                                                )
                                                    void mutate(
                                                        'delete',
                                                        `/teams/${team.id}`,
                                                        { name: deleteName },
                                                    );
                                            }}
                                        >
                                            <label>
                                                Для удаления введите «{team.name}»
                                                <input
                                                    required
                                                    value={deleteName}
                                                    onChange={(event) =>
                                                        setDeleteName(
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            </label>
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                disabled={
                                                    busy || deleteName !== team.name
                                                }
                                            >
                                                Удалить команду окончательно
                                            </Button>
                                        </form>
                                    </>
                                )}
                            </GlassCard>
                        )}
                        <GlassCard className="work-card">
                            <h2>Журнал действий</h2>
                            {activities.length === 0 ? (
                                <p className="work-help">Действий пока нет.</p>
                            ) : (
                                <div className="work-list">
                                    {activities.map((activity) => (
                                        <div className="work-task" key={activity.id}>
                                            <strong>
                                                {activity.actor_name ||
                                                    'Удалённый пользователь'}
                                            </strong>
                                            <p>{activityLabel(activity)}</p>
                                            <small>
                                                {new Date(
                                                    activity.created_at,
                                                ).toLocaleString('ru-RU')}
                                            </small>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </GlassCard>
                    </>
                )}
            </AppShell>
        </>
    );
}
