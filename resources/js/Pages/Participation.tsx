import { scopedUrl } from '../lib/workspace';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { TenderWorkspaceHeader } from '../Components/TenderWorkspaceHeader';
import { useState } from 'react';
import { ParticipationRemovalDialog } from '../Components/ParticipationRemovalDialog';
import {
    changeParticipations,
    participationError,
    type ParticipationTarget,
} from '../lib/participationLifecycle';
import { Badge, Button, GlassCard } from '../Components/ui';
import {
    formatWorkDeadline,
    stages,
    stageLabel,
    type Stage,
} from '../lib/participation';
import type { TeamScope } from '../Components/WorkspacePicker';
import type { PageProps } from '../types';

type Row = {
    id: number;
    version: number;
    deleted_at: string | null;
    expired_before_submission: boolean;
    tender_id: number;
    assignee_id: number | null;
    title: string;
    stage: Stage;
    loss_reason: string | null;
    deadline_at: string | null;
    items_count: number;
    completed_count: number;
    unread_comments: number;
    decision: 'go' | 'no_go' | null;
};

export default function ParticipationPage() {
    const {
        participations,
        stage,
        counts,
        team,
        members,
        can_edit,
        view,
        expiredCount,
        deletedCount,
    } = usePage<
        PageProps<
            TeamScope & {
                participations: {
                    data: Row[];
                    total: number;
                    current_page: number;
                    last_page: number;
                    prev_page_url: string | null;
                    next_page_url: string | null;
                };
                view: 'current' | 'expired' | 'deleted';
                expiredCount: number;
                deletedCount: number;
                stage: Stage | null;
                counts: Record<string, number>;
            }
        >
    >().props;
    const [editing, setEditing] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const [removing, setRemoving] = useState<ParticipationTarget[]>([]);
    const [undo, setUndo] = useState<ParticipationTarget[]>([]);
    const [restored, setRestored] = useState(0);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const listUrl = (nextStage: Stage | null = stage, nextView = view) =>
        scopedUrl(
            `/participation?view=${nextView}${nextStage ? `&stage=${nextStage}` : ''}`,
            team,
        );
    const refresh = () => {
        setSelected([]);
        setEditing(false);
        router.get(listUrl(), {}, { preserveState: true, preserveScroll: true });
    };
    const restore = async (items: ParticipationTarget[]) => {
        setBusy(true);
        setError('');
        try {
            await changeParticipations('restore', items, team);
            setUndo([]);
            setRestored(items.length);
            refresh();
        } catch (failure) {
            setError(participationError(failure));
        } finally {
            setBusy(false);
        }
    };
    const selectedRows = participations.data.filter((row) => selected.includes(row.id));
    return (
        <>
            <Head title="Участие в тендерах" />
            <AppShell
                title="Участие"
                eyebrow="От отбора до результата"
                className="work-page participation-page"
                activeNav="/tenders"
            >
                <TenderWorkspaceHeader tab="participation" />
                <GlassCard className="participation-toolbar">
                    <label className="form-field">
                        <span className="sr-only">Список заявок</span>
                        <select
                            value={view}
                            onChange={(event) =>
                                router.get(
                                    listUrl(null, event.target.value as typeof view),
                                )
                            }
                        >
                            <option value="current">Заявки</option>
                            {expiredCount > 0 || view === 'expired' ? (
                                <option value="expired">Срок истёк</option>
                            ) : null}
                            {deletedCount > 0 || view === 'deleted' ? (
                                <option value="deleted">Удалённые</option>
                            ) : null}
                        </select>
                    </label>
                    {can_edit && participations.data.length > 0 ? (
                        <Button
                            variant="secondary"
                            size="sm"
                            onClick={() => {
                                setEditing(!editing);
                                setSelected([]);
                            }}
                            disabled={busy}
                        >
                            {editing ? 'Готово' : 'Выбрать'}
                        </Button>
                    ) : null}
                    {view !== 'deleted' ? (
                        <label className="form-field participation-stage-select">
                            <span className="sr-only">Этап участия</span>
                            <select
                                value={stage ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        listUrl(
                                            (event.target.value ||
                                                null) as Stage | null,
                                        ),
                                    )
                                }
                            >
                                <option value="">
                                    Все этапы (
                                    {Object.values(counts).reduce(
                                        (sum, value) => sum + Number(value),
                                        0,
                                    )}
                                    )
                                </option>
                                {stages
                                    .filter(
                                        (s) =>
                                            view !== 'expired' ||
                                            ['studying', 'preparing'].includes(s.value),
                                    )
                                    .map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label} ({counts[s.value] ?? 0})
                                        </option>
                                    ))}
                            </select>
                        </label>
                    ) : null}
                    {view === 'current' && expiredCount > 0 ? (
                        <Link
                            className="participation-expired-link"
                            href={listUrl(null, 'expired')}
                        >
                            Срок подачи истёк: {expiredCount}
                        </Link>
                    ) : null}
                </GlassCard>
                {view === 'expired' ? (
                    <p className="participation-list-note">
                        Срок подачи истёк у заявок на этапах «Изучаем» и «Готовим
                        заявку». Если вы успели подать заявку, откройте её и выберите
                        «Подали».
                    </p>
                ) : null}
                {view === 'deleted' ? (
                    <p className="participation-list-note">
                        Эти заявки не учитываются в статистике и календаре. При
                        восстановлении задачи и документы вернутся; напоминания
                        останутся выключены.
                    </p>
                ) : null}
                {undo.length > 0 ? (
                    <div className="participation-feedback" role="status">
                        <p>Удалено заявок: {undo.length}.</p>
                        <Button
                            variant="ghost"
                            disabled={busy}
                            onClick={() => void restore(undo)}
                        >
                            Отменить удаление
                        </Button>
                    </div>
                ) : null}
                {restored > 0 ? (
                    <div className="participation-feedback" role="status">
                        <p>
                            Восстановлено заявок: {restored}. Заявки с истёкшим сроком —
                            в отдельном списке.
                        </p>
                        <Link href={listUrl(null, 'current')}>К заявкам</Link>
                    </div>
                ) : null}
                {error ? (
                    <div className="work-error" role="alert">
                        <p>{error}</p>
                        <Button
                            variant="secondary"
                            onClick={() => router.reload()}
                            disabled={busy}
                        >
                            Обновить список
                        </Button>
                    </div>
                ) : null}
                {editing ? (
                    <div
                        className="participation-selection"
                        role="group"
                        aria-label="Выбор заявок"
                    >
                        <label>
                            <input
                                type="checkbox"
                                checked={
                                    selected.length === participations.data.length &&
                                    selected.length > 0
                                }
                                onChange={(event) =>
                                    setSelected(
                                        event.target.checked
                                            ? participations.data.map((row) => row.id)
                                            : [],
                                    )
                                }
                            />
                            Выбрать на странице
                        </label>
                        <span aria-live="polite">Выбрано: {selected.length}</span>
                        <Button
                            variant={view === 'deleted' ? 'secondary' : 'danger'}
                            disabled={busy || selectedRows.length === 0}
                            onClick={() =>
                                view === 'deleted'
                                    ? void restore(selectedRows)
                                    : setRemoving(selectedRows)
                            }
                        >
                            {view === 'deleted'
                                ? 'Восстановить выбранные'
                                : 'Удалить выбранные'}
                        </Button>
                    </div>
                ) : null}
                {view !== 'deleted' ? (
                    <nav className="work-stages" aria-label="Этап участия">
                        <Link
                            className={!stage ? 'is-active' : ''}
                            aria-current={!stage ? 'page' : undefined}
                            href={listUrl(null)}
                        >
                            Все{' '}
                            <strong>
                                {Object.values(counts).reduce(
                                    (sum, n) => sum + Number(n),
                                    0,
                                )}
                            </strong>
                        </Link>
                        {stages
                            .filter(
                                (s) =>
                                    view !== 'expired' ||
                                    ['studying', 'preparing'].includes(s.value),
                            )
                            .map((s) => (
                                <Link
                                    key={s.value}
                                    className={stage === s.value ? 'is-active' : ''}
                                    aria-current={
                                        stage === s.value ? 'page' : undefined
                                    }
                                    href={listUrl(s.value)}
                                >
                                    {s.label} <strong>{counts[s.value] ?? 0}</strong>
                                </Link>
                            ))}
                    </nav>
                ) : null}
                {participations.data.length === 0 ? (
                    <GlassCard className="work-card participation-empty">
                        <span className="participation-empty__icon">
                            <Icon name="tenders" size={24} />
                        </span>
                        <h2>
                            {view === 'deleted'
                                ? 'Удалённых заявок нет'
                                : view === 'expired'
                                  ? 'Просроченных заявок нет'
                                  : expiredCount > 0 && !stage
                                    ? 'Нет актуальных заявок'
                                    : 'Заявок пока нет'}
                        </h2>
                        <p>
                            {view === 'deleted'
                                ? 'Здесь появятся заявки, которые вы удалите из работы.'
                                : view === 'expired'
                                  ? 'Нет неподанных заявок с истёкшим сроком.'
                                  : stage
                                    ? 'На этом этапе нет закупок.'
                                    : expiredCount > 0
                                      ? 'Заявки с истёкшим сроком доступны отдельно. Их можно удалить или уточнить этап участия.'
                                      : 'Откройте закупку в ленте и нажмите «Участие и задачи».'}
                        </p>
                        <Link
                            className="button button--secondary"
                            href={
                                view !== 'current'
                                    ? listUrl(null, 'current')
                                    : expiredCount > 0
                                      ? listUrl(null, 'expired')
                                      : scopedUrl('/tenders', team)
                            }
                        >
                            {view !== 'current'
                                ? 'К заявкам'
                                : expiredCount > 0
                                  ? 'Посмотреть заявки с истёкшим сроком'
                                  : 'Перейти к тендерам'}
                        </Link>
                    </GlassCard>
                ) : null}
                <div className="work-list">
                    {participations.data.map((row) => (
                        <GlassCard
                            key={row.id}
                            as="article"
                            className="work-card participation-card"
                        >
                            {editing ? (
                                <label className="participation-select">
                                    <input
                                        type="checkbox"
                                        aria-label={`Выбрать заявку «${row.title}»`}
                                        checked={selected.includes(row.id)}
                                        onChange={(event) =>
                                            setSelected((current) =>
                                                event.target.checked
                                                    ? [...current, row.id]
                                                    : current.filter(
                                                          (id) => id !== row.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <span>Выбрать</span>
                                </label>
                            ) : null}
                            {row.expired_before_submission && !row.deleted_at ? (
                                <Badge tone="warning">Срок подачи истёк</Badge>
                            ) : null}
                            {team && (
                                <p>
                                    Ответственный:{' '}
                                    {members.find((m) => m.id === row.assignee_id)
                                        ?.name ?? 'Не назначен'}
                                </p>
                            )}
                            <Badge
                                tone={
                                    row.deleted_at
                                        ? 'neutral'
                                        : row.stage === 'won'
                                          ? 'success'
                                          : row.stage === 'lost'
                                            ? 'danger'
                                            : 'accent'
                                }
                            >
                                {row.deleted_at
                                    ? 'Удалена из заявок'
                                    : stageLabel(row.stage)}
                            </Badge>
                            {row.decision ? (
                                <Badge
                                    tone={row.decision === 'go' ? 'success' : 'danger'}
                                >
                                    {row.decision === 'go'
                                        ? 'Участвуем'
                                        : 'Не участвуем'}
                                </Badge>
                            ) : null}
                            <h2>
                                <Link
                                    href={scopedUrl(
                                        `/tenders/${row.tender_id}/work`,
                                        team,
                                    )}
                                >
                                    {row.title}
                                </Link>
                            </h2>
                            <p>
                                {row.deadline_at
                                    ? `Подача до ${formatWorkDeadline(row.deadline_at)}`
                                    : 'Срок подачи не указан в источнике'}
                            </p>
                            {row.items_count > 0 && !row.deleted_at ? (
                                <div className="work-progress">
                                    <progress
                                        max={Math.max(row.items_count, 1)}
                                        value={row.completed_count}
                                        aria-label="Готовность чек-листа"
                                    />
                                    <span>
                                        {row.completed_count} / {row.items_count} задач
                                    </span>
                                </div>
                            ) : null}
                            {row.unread_comments > 0 ? (
                                <p className="work-unread">
                                    Новых комментариев: {row.unread_comments}
                                </p>
                            ) : null}
                            {row.loss_reason ? (
                                <p className="work-reason">
                                    Причина проигрыша: {row.loss_reason}
                                </p>
                            ) : null}
                            <div className="work-actions">
                                {view !== 'deleted' ? (
                                    <Link
                                        href={scopedUrl(
                                            `/tenders/${row.tender_id}/work`,
                                            team,
                                        )}
                                        className="button button--secondary"
                                    >
                                        Открыть заявку
                                    </Link>
                                ) : null}
                                {can_edit && !editing ? (
                                    <Button
                                        variant="ghost"
                                        disabled={busy}
                                        onClick={() =>
                                            view === 'deleted'
                                                ? void restore([row])
                                                : setRemoving([row])
                                        }
                                    >
                                        {view === 'deleted'
                                            ? 'Восстановить'
                                            : 'Удалить из заявок'}
                                    </Button>
                                ) : null}
                            </div>
                        </GlassCard>
                    ))}
                </div>
                {participations.last_page > 1 ? (
                    <nav className="work-toolbar" aria-label="Страницы заявок">
                        {participations.prev_page_url ? (
                            <Link
                                href={participations.prev_page_url}
                                className="button button--secondary"
                            >
                                Назад
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span>
                            {participations.current_page} / {participations.last_page}
                        </span>
                        {participations.next_page_url ? (
                            <Link
                                href={participations.next_page_url}
                                className="button button--secondary"
                            >
                                Далее
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                ) : null}
            </AppShell>
            <ParticipationRemovalDialog
                key={removing.map((item) => `${item.id}:${item.version}`).join(',')}
                items={removing}
                team={team}
                onClose={() => setRemoving([])}
                onRemoved={(items) => {
                    setRemoving([]);
                    setUndo(items);
                    setRestored(0);
                    setError('');
                    refresh();
                }}
            />
        </>
    );
}
