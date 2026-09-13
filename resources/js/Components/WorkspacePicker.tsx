import { Link, router, usePage } from '@inertiajs/react';
import type { PageProps } from '../types';

export type TeamScope = {
    team: { id: number; name: string; role: string; archived_at: string | null } | null;
    teams: { id: number; name: string; role: string; archived_at: string | null }[];
    members: {
        id: number;
        name: string;
        role: string;
        active_applications?: number;
        open_tasks?: number;
        overdue_tasks?: number;
    }[];
    can_edit: boolean;
};
export function WorkspacePicker({ path }: { path: string }) {
    const { team, teams = [] } = usePage<PageProps<TeamScope>>().props;
    return (
        <div className="work-form">
            <label>
                Рабочее пространство
                <select
                    value={team?.id ?? ''}
                    onChange={(e) =>
                        router.get(
                            path,
                            e.target.value ? { team_id: e.target.value } : {},
                            { preserveState: false },
                        )
                    }
                >
                    <option value="">Личное</option>
                    {teams.map((t) => (
                        <option key={t.id} value={t.id}>
                            {t.name}
                            {t.archived_at ? ' · архив' : ''}
                        </option>
                    ))}
                </select>
            </label>
            <Link href="/teams">Управление командами</Link>
            {team && (
                <p className="work-help">
                    Общие заявки команды «{team.name}».{' '}
                    {team.archived_at
                        ? 'Команда в архиве: данные доступны только для чтения.'
                        : team.role === 'viewer'
                          ? 'Доступ только для просмотра.'
                          : 'Изменения видны всем участникам.'}
                </p>
            )}
        </div>
    );
}
export function AssigneeSelect({
    value,
    onChange,
    members,
    disabled,
    label = 'Исполнитель',
}: {
    value: number | null;
    onChange: (id: number | null) => void;
    members: TeamScope['members'];
    disabled?: boolean;
    label?: string;
}) {
    return (
        <label>
            {label}
            <select
                value={value ?? ''}
                disabled={disabled}
                onChange={(e) =>
                    onChange(e.target.value ? Number(e.target.value) : null)
                }
            >
                <option value="">Не назначен</option>
                {members
                    .filter((m) => m.role !== 'viewer')
                    .map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.name || `Участник ${m.id}`}
                        </option>
                    ))}
            </select>
        </label>
    );
}
