import { scopedUrl } from '../lib/workspace';
import axios from 'axios';
import { useState, type FormEvent } from 'react';
import { Badge, Button, GlassCard } from './ui';
import type { TeamScope } from './WorkspacePicker';
import type { ApprovalState, Economics } from '../lib/participation';

const fields = [
    ['planned_revenue', 'Цена предложения'],
    ['planned_cost', 'Себестоимость'],
    ['security_cost', 'Стоимость обеспечения'],
    ['commission_cost', 'Комиссии'],
    ['other_cost', 'Прочие расходы'],
    ['actual_revenue', 'Фактическая выручка'],
    ['actual_cost', 'Фактические расходы'],
] as const;

const money = (value: number): string =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(
        value,
    );

export function ParticipationEconomics({
    initial,
    initialApproval,
    root,
    team,
    canEdit,
}: {
    initial: Economics;
    initialApproval: ApprovalState;
    root: string;
    team: TeamScope['team'];
    canEdit: boolean;
}) {
    const [economics, setEconomics] = useState(initial);
    const [approval, setApproval] = useState(initialApproval);
    const [draft, setDraft] = useState(
        () =>
            Object.fromEntries(
                fields.map(([key]) => [key, initial[key] ?? '']),
            ) as Record<(typeof fields)[number][0], string>,
    );
    const [decision, setDecision] = useState<Economics['decision']>(initial.decision);
    const [note, setNote] = useState(initial.decision_note ?? '');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [approvalNote, setApprovalNote] = useState('');
    const [voteComment, setVoteComment] = useState('');

    const save = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        setBusy(true);
        setMessage('');
        try {
            const response = await window.axios.patch<{
                economics: Economics;
                approval: ApprovalState;
            }>(scopedUrl(`${root}/economics`, team), {
                ...Object.fromEntries(
                    Object.entries(draft).map(([key, value]) => [
                        key,
                        value === '' ? null : value,
                    ]),
                ),
                decision,
                decision_note: note || null,
                version: economics.version,
            });
            setEconomics(response.data.economics);
            setApproval(response.data.approval);
            setMessage('Экономика заявки сохранена.');
        } catch (error) {
            setMessage(
                axios.isAxiosError<{ message?: string }>(error)
                    ? (error.response?.data.message ?? 'Не удалось сохранить расчёт.')
                    : 'Не удалось сохранить расчёт.',
            );
        } finally {
            setBusy(false);
        }
    };

    const requestApproval = async (): Promise<void> => {
        setBusy(true);
        setMessage('');
        try {
            const response = await window.axios.post<{ approval: ApprovalState }>(
                scopedUrl(`${root}/approval-requests`, team),
                { note: approvalNote || null },
            );
            setApproval(response.data.approval);
            setApprovalNote('');
            setMessage('Запрос на согласование отправлен.');
        } catch (error) {
            setMessage(
                axios.isAxiosError<{ message?: string }>(error)
                    ? (error.response?.data.message ??
                          'Не удалось запросить согласование.')
                    : 'Не удалось запросить согласование.',
            );
        } finally {
            setBusy(false);
        }
    };

    const vote = async (decision: 'approved' | 'rejected'): Promise<void> => {
        if (!approval.current) return;
        setBusy(true);
        setMessage('');
        try {
            const response = await window.axios.patch<{ approval: ApprovalState }>(
                scopedUrl(
                    `${root}/approval-requests/${approval.current.id}/vote`,
                    team,
                ),
                {
                    decision,
                    comment: voteComment || null,
                    version: approval.current.version,
                },
            );
            setApproval(response.data.approval);
            setVoteComment('');
            setMessage(
                decision === 'approved' ? 'Решение одобрено.' : 'Решение отклонено.',
            );
        } catch (error) {
            setMessage(
                axios.isAxiosError<{ message?: string }>(error)
                    ? (error.response?.data.message ?? 'Не удалось сохранить голос.')
                    : 'Не удалось сохранить голос.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <GlassCard className="work-card economics-card">
            <div className="economics-heading">
                <div>
                    <h2>Экономика заявки</h2>
                    <p>Оцените затраты и зафиксируйте решение до подачи.</p>
                </div>
                {economics.decision ? (
                    <Badge tone={economics.decision === 'go' ? 'success' : 'danger'}>
                        {economics.decision === 'go' ? 'Участвуем' : 'Не участвуем'}
                    </Badge>
                ) : null}
            </div>
            <div className="economics-metrics">
                <div>
                    <span>Расходы по плану</span>
                    <strong>{money(economics.planned_expenses)}</strong>
                </div>
                <div>
                    <span>Плановая маржа</span>
                    <strong>{money(economics.planned_margin)}</strong>
                    <small>
                        {economics.planned_margin_percent === null
                            ? 'процент не рассчитан'
                            : `${economics.planned_margin_percent}% от выручки`}
                    </small>
                </div>
                <div>
                    <span>Фактическая маржа</span>
                    <strong>{money(economics.actual_margin)}</strong>
                </div>
            </div>
            <form className="work-form" onSubmit={save}>
                <div className="economics-fields">
                    {fields.map(([key, label]) => (
                        <label key={key}>
                            {label}, ₽
                            <input
                                disabled={!canEdit || busy}
                                min="0"
                                max="9999999999999999.99"
                                step="0.01"
                                type="number"
                                value={draft[key]}
                                onChange={(event) =>
                                    setDraft({ ...draft, [key]: event.target.value })
                                }
                            />
                        </label>
                    ))}
                </div>
                <label>
                    Решение
                    <select
                        disabled={!canEdit || busy}
                        value={decision ?? ''}
                        onChange={(event) =>
                            setDecision(
                                (event.target.value || null) as Economics['decision'],
                            )
                        }
                    >
                        <option value="">Не принято</option>
                        <option value="go">Участвуем</option>
                        <option value="no_go">Не участвуем</option>
                    </select>
                </label>
                <label>
                    Обоснование
                    <textarea
                        disabled={!canEdit || busy}
                        maxLength={2000}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder="Риски, ограничения и аргументы решения"
                    />
                </label>
                <Button disabled={!canEdit || busy} type="submit">
                    {busy ? 'Сохраняем…' : 'Сохранить расчёт'}
                </Button>
                {message ? (
                    <p className="work-help" role="status">
                        {message}
                    </p>
                ) : null}
            </form>
            {team && approval.required ? (
                <div className="approval-panel">
                    <div className="economics-heading">
                        <div>
                            <h3>Согласование go/no-go</h3>
                            <p>
                                После изменения экономики потребуется новое одобрение.
                            </p>
                        </div>
                        <Badge
                            tone={
                                approval.current?.status === 'approved'
                                    ? 'success'
                                    : approval.current?.status === 'rejected'
                                      ? 'danger'
                                      : 'warning'
                            }
                        >
                            {approvalStatus(approval.current?.status)}
                        </Badge>
                    </div>
                    {approval.current ? (
                        <>
                            <p>
                                Голосов за:{' '}
                                {
                                    approval.current.votes.filter(
                                        (item) => item.decision === 'approved',
                                    ).length
                                }{' '}
                                из {approval.current.required_approvals}
                            </p>
                            {approval.current.note ? (
                                <p>{approval.current.note}</p>
                            ) : null}
                            {approval.current.votes.map((item) => (
                                <p key={`${item.approver_id}-${item.decision}`}>
                                    <b>{item.approver_name ?? 'Удалённый участник'}:</b>{' '}
                                    {item.decision === 'approved'
                                        ? 'одобрил'
                                        : 'отклонил'}
                                    {item.comment ? ` — ${item.comment}` : ''}
                                </p>
                            ))}
                        </>
                    ) : null}
                    {canEdit &&
                    (!approval.current ||
                        ['rejected', 'superseded'].includes(
                            approval.current.status,
                        )) ? (
                        <div className="work-form">
                            <textarea
                                maxLength={2000}
                                placeholder="Что необходимо согласовать"
                                value={approvalNote}
                                onChange={(event) =>
                                    setApprovalNote(event.target.value)
                                }
                            />
                            <Button
                                disabled={busy || economics.decision !== 'go'}
                                onClick={requestApproval}
                                type="button"
                            >
                                Запросить согласование
                            </Button>
                        </div>
                    ) : null}
                    {canEdit && approval.current?.status === 'pending' ? (
                        <div className="work-form">
                            <textarea
                                maxLength={2000}
                                placeholder="Комментарий согласующего"
                                value={voteComment}
                                onChange={(event) => setVoteComment(event.target.value)}
                            />
                            <div className="approval-actions">
                                <Button
                                    disabled={busy}
                                    onClick={() => vote('approved')}
                                    type="button"
                                >
                                    Одобрить
                                </Button>
                                <Button
                                    disabled={busy}
                                    onClick={() => vote('rejected')}
                                    type="button"
                                    variant="secondary"
                                >
                                    Отклонить
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </div>
            ) : null}
        </GlassCard>
    );
}

function approvalStatus(status?: string): string {
    return (
        {
            pending: 'На согласовании',
            approved: 'Одобрено',
            rejected: 'Отклонено',
            superseded: 'Расчёт изменён',
        }[status ?? ''] ?? 'Не запрошено'
    );
}
