import { scopedUrl } from '../lib/workspace';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Button, GlassCard } from '../Components/ui';
import { WorkspacePicker, type TeamScope } from '../Components/WorkspacePicker';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import type { PageProps } from '../types';

export default function Teams() {
    const { team, members, invitations, auth } = usePage<
        PageProps<
            TeamScope & {
                invitations: { id: number; role: string; expires_at: string }[];
            }
        >
    >().props;
    const [name, setName] = useState('');
    const [role, setRole] = useState('member');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [invite, setInvite] = useState('');
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
            } else if (response.data.team_id)
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
                                        {team.role === 'owner' &&
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
                                                </div>
                                            )}
                                        {m.id === auth.user?.id &&
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
                        {team.role === 'owner' && (
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
                    </>
                )}
            </AppShell>
        </>
    );
}
