import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, GlassCard, InlineAlert } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import {
    categoryLabel,
    statusLabel,
    supportDate,
    type SupportStatus,
    type SupportTicketDetail,
} from '../lib/support';
import type { Access, PageProps } from '../types';

type Diagnostics = {
    access: Access;
    monitorings: Array<{
        id: number;
        status: string;
        sources: Array<{
            source: string;
            state: string;
            message: string;
            last_success_at: string | null;
            last_failure_at: string | null;
            next_attempt_at: string | null;
        }>;
    }>;
    deliveries: Array<{
        type: string;
        status: string;
        message: string;
        scheduled_at: string | null;
        completed_at: string | null;
    }>;
};

const eventLabel: Record<string, string> = {
    created: 'Обращение создано',
    user_reply: 'Ответ пользователя',
    staff_reply: 'Ответ поддержки',
    workflow_changed: 'Изменён статус или ответственный',
    access_granted: 'Выдан ручной доступ',
    access_revoked: 'Отозван ручной доступ',
};

export default function AdminSupportTicket() {
    const { ticket, diagnostics, assignees, manualGrant, grantBlockReason } = usePage<
        PageProps<{
            ticket: SupportTicketDetail;
            diagnostics: Diagnostics;
            assignees: Array<{ id: number; name: string | null }>;
            manualGrant: { id: number; ends_at: string | null } | null;
            grantBlockReason: string | null;
        }>
    >().props;
    const workflow = useForm<{
        status: SupportStatus;
        assignee_id: number | null;
        reason: string;
    }>({
        status: ticket.status,
        assignee_id: ticket.assignee_id,
        reason: '',
    });
    const reply = useForm({ body: '' });
    const grant = useForm({ days: 3, reason: '' });
    const revoke = useForm({ reason: '', confirm: false });
    const access = presentAccess(diagnostics.access);

    const saveWorkflow = (event: FormEvent): void => {
        event.preventDefault();
        workflow.patch(`/support/admin/${ticket.id}`, {
            onSuccess: () => workflow.setData('reason', ''),
        });
    };
    const sendReply = (event: FormEvent): void => {
        event.preventDefault();
        reply.post(`/support/admin/${ticket.id}/reply`, {
            onSuccess: () => reply.reset(),
        });
    };
    const grantAccess = (event: FormEvent): void => {
        event.preventDefault();
        grant.post(`/support/admin/${ticket.id}/access`, {
            onSuccess: () => grant.reset('reason'),
        });
    };
    const revokeAccess = (event: FormEvent): void => {
        event.preventDefault();
        revoke.delete(`/support/admin/${ticket.id}/access`, {
            onSuccess: () => revoke.reset(),
        });
    };

    return (
        <>
            <Head title={`Поддержка #${ticket.id}`} />
            <AppShell
                backHref="/support/admin"
                eyebrow={`Пользователь #${ticket.user_id}`}
                title={`Обращение #${ticket.id}`}
                wide
            >
                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <div className="space-y-5">
                        <div className="flex flex-wrap items-center gap-3">
                            <Badge>{categoryLabel[ticket.category]}</Badge>
                            <Badge>{statusLabel[ticket.status]}</Badge>
                        </div>
                        <ol className="space-y-3">
                            {ticket.messages.map((message) => (
                                <li key={message.id}>
                                    <GlassCard
                                        className="p-4 sm:p-5"
                                        tone={message.is_staff ? 'accent' : 'quiet'}
                                    >
                                        <strong>
                                            {message.is_staff
                                                ? 'Поддержка'
                                                : 'Пользователь'}
                                        </strong>
                                        <p className="mt-2 whitespace-pre-wrap break-words">
                                            {message.body}
                                        </p>
                                        <small>{supportDate(message.created_at)}</small>
                                    </GlassCard>
                                </li>
                            ))}
                        </ol>
                        <GlassCard className="p-4 sm:p-5">
                            <h2 className="text-lg font-semibold">
                                Ответ пользователю
                            </h2>
                            <form className="mt-3 space-y-3" onSubmit={sendReply}>
                                <label className="form-field">
                                    <span>Сообщение</span>
                                    <textarea
                                        maxLength={2000}
                                        minLength={2}
                                        onChange={(event) =>
                                            reply.setData('body', event.target.value)
                                        }
                                        required
                                        rows={4}
                                        value={reply.data.body}
                                    />
                                </label>
                                {reply.errors.body ? <p>{reply.errors.body}</p> : null}
                                <Button disabled={reply.processing} type="submit">
                                    Отправить ответ
                                </Button>
                            </form>
                        </GlassCard>
                    </div>
                    <div className="space-y-5">
                        <GlassCard className="p-4 sm:p-5">
                            <h2 className="text-lg font-semibold">
                                Работа с обращением
                            </h2>
                            <form className="mt-3 space-y-3" onSubmit={saveWorkflow}>
                                <label className="form-field">
                                    <span>Статус</span>
                                    <select
                                        onChange={(event) =>
                                            workflow.setData(
                                                'status',
                                                event.target.value as SupportStatus,
                                            )
                                        }
                                        value={workflow.data.status}
                                    >
                                        {Object.entries(statusLabel).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                                <label className="form-field">
                                    <span>Ответственный</span>
                                    <select
                                        onChange={(event) =>
                                            workflow.setData(
                                                'assignee_id',
                                                event.target.value
                                                    ? Number(event.target.value)
                                                    : null,
                                            )
                                        }
                                        value={workflow.data.assignee_id ?? ''}
                                    >
                                        <option value="">Не назначен</option>
                                        {assignees.map((assignee) => (
                                            <option
                                                key={assignee.id}
                                                value={assignee.id}
                                            >
                                                {assignee.name ??
                                                    `Администратор #${assignee.id}`}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="form-field">
                                    <span>Причина изменения</span>
                                    <textarea
                                        maxLength={500}
                                        minLength={10}
                                        onChange={(event) =>
                                            workflow.setData(
                                                'reason',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        rows={3}
                                        value={workflow.data.reason}
                                    />
                                </label>
                                {Object.values(workflow.errors).map((error) => (
                                    <p className="text-sm text-red-300" key={error}>
                                        {error}
                                    </p>
                                ))}
                                <Button disabled={workflow.processing} type="submit">
                                    Сохранить с записью в журнал
                                </Button>
                            </form>
                        </GlassCard>
                        <InlineAlert title="Диагностика без содержимого" tone="neutral">
                            Здесь нет поисковых фраз, карточек тендеров, payload
                            уведомлений и технических секретов.
                        </InlineAlert>
                        <GlassCard className="p-4 sm:p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="text-lg font-semibold">
                                    Доступ пользователя
                                </h2>
                                <Badge tone={access.tone}>
                                    {manualGrant ? 'Ручной доступ' : access.badge}
                                </Badge>
                            </div>
                            <div className="mt-3 space-y-1 text-sm">
                                <p>
                                    {manualGrant
                                        ? 'Basic выдан поддержкой для решения обращения.'
                                        : access.description}
                                </p>
                                <p>
                                    Тариф:{' '}
                                    {diagnostics.access.plan_code === 'basic'
                                        ? 'Basic'
                                        : diagnostics.access.plan_code === 'pro'
                                          ? 'Про'
                                          : (diagnostics.access.plan_code ??
                                            'не назначен')}
                                </p>
                                <p>
                                    Срок:{' '}
                                    {diagnostics.access.ends_at
                                        ? `до ${supportDate(diagnostics.access.ends_at)}`
                                        : 'без даты окончания'}
                                </p>
                            </div>
                            <div className="mt-5 border-t border-[var(--tf-line)] pt-4">
                                <h3 className="font-semibold">
                                    Ручной доступ для решения обращения
                                </h3>
                                <p className="mt-2 text-sm">
                                    Только Basic на 1–7 дней. Это не оплата и не
                                    продление подписки. Причина сохраняется в служебном
                                    журнале и не показывается пользователю.
                                </p>
                                {manualGrant ? (
                                    <form
                                        className="mt-4 space-y-3"
                                        onSubmit={revokeAccess}
                                    >
                                        <InlineAlert
                                            title="Ручной доступ действует"
                                            tone="neutral"
                                        >
                                            До{' '}
                                            {manualGrant.ends_at
                                                ? supportDate(manualGrant.ends_at)
                                                : 'без даты окончания'}
                                            . Отзыв остановит мониторинги и ожидающие
                                            доставки, если другого активного доступа
                                            нет.
                                        </InlineAlert>
                                        <label className="form-field">
                                            <span>Причина отзыва</span>
                                            <textarea
                                                maxLength={500}
                                                minLength={10}
                                                onChange={(event) =>
                                                    revoke.setData(
                                                        'reason',
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                                rows={3}
                                                value={revoke.data.reason}
                                            />
                                        </label>
                                        <label className="flex items-start gap-3 text-sm">
                                            <input
                                                checked={revoke.data.confirm}
                                                className="mt-1 h-5 w-5 shrink-0"
                                                onChange={(event) =>
                                                    revoke.setData(
                                                        'confirm',
                                                        event.target.checked,
                                                    )
                                                }
                                                required
                                                type="checkbox"
                                            />
                                            <span>
                                                Подтверждаю отзыв именно ручного
                                                доступа.
                                            </span>
                                        </label>
                                        {Object.values(revoke.errors).map((error) => (
                                            <p
                                                className="text-sm text-red-300"
                                                key={error}
                                                role="alert"
                                            >
                                                {error}
                                            </p>
                                        ))}
                                        <Button
                                            disabled={
                                                revoke.processing ||
                                                !revoke.data.confirm
                                            }
                                            type="submit"
                                            variant="danger"
                                        >
                                            Отозвать ручной доступ
                                        </Button>
                                    </form>
                                ) : grantBlockReason ? (
                                    <InlineAlert
                                        title="Выдача сейчас недоступна"
                                        tone="neutral"
                                    >
                                        {grantBlockReason}
                                    </InlineAlert>
                                ) : (
                                    <form
                                        className="mt-4 space-y-3"
                                        onSubmit={grantAccess}
                                    >
                                        <label className="form-field">
                                            <span>Срок доступа</span>
                                            <select
                                                onChange={(event) =>
                                                    grant.setData(
                                                        'days',
                                                        Number(event.target.value),
                                                    )
                                                }
                                                value={grant.data.days}
                                            >
                                                {[1, 2, 3, 4, 5, 6, 7].map((days) => (
                                                    <option key={days} value={days}>
                                                        {days}{' '}
                                                        {days === 1
                                                            ? 'день'
                                                            : days < 5
                                                              ? 'дня'
                                                              : 'дней'}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                        <label className="form-field">
                                            <span>Причина выдачи</span>
                                            <textarea
                                                maxLength={500}
                                                minLength={10}
                                                onChange={(event) =>
                                                    grant.setData(
                                                        'reason',
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                                rows={3}
                                                value={grant.data.reason}
                                            />
                                        </label>
                                        {Object.values(grant.errors).map((error) => (
                                            <p
                                                className="text-sm text-red-300"
                                                key={error}
                                                role="alert"
                                            >
                                                {error}
                                            </p>
                                        ))}
                                        <Button
                                            disabled={grant.processing}
                                            type="submit"
                                            variant="secondary"
                                        >
                                            Выдать Basic с записью в журнал
                                        </Button>
                                    </form>
                                )}
                            </div>
                        </GlassCard>
                        <GlassCard className="p-4 sm:p-5">
                            <h2 className="text-lg font-semibold">Источники</h2>
                            {diagnostics.monitorings.length === 0 ? (
                                <p>Мониторингов нет.</p>
                            ) : null}
                            <ul className="mt-3 space-y-3">
                                {diagnostics.monitorings.map((monitoring) => (
                                    <li key={monitoring.id}>
                                        <strong>
                                            Мониторинг #{monitoring.id} ·{' '}
                                            {monitoring.status}
                                        </strong>
                                        {monitoring.sources.length === 0 ? (
                                            <p>Подключённых источников нет.</p>
                                        ) : null}
                                        {monitoring.sources.map((source, index) => (
                                            <p key={`${source.source}-${index}`}>
                                                {source.source}: {source.message}
                                            </p>
                                        ))}
                                    </li>
                                ))}
                            </ul>
                        </GlassCard>
                        <GlassCard className="p-4 sm:p-5">
                            <h2 className="text-lg font-semibold">Доставка</h2>
                            {diagnostics.deliveries.length === 0 ? (
                                <p>Уведомлений нет.</p>
                            ) : null}
                            <ul className="mt-3 space-y-2">
                                {diagnostics.deliveries.map((delivery, index) => (
                                    <li key={index}>
                                        {delivery.type}: {delivery.message}
                                    </li>
                                ))}
                            </ul>
                        </GlassCard>
                        <GlassCard className="p-4 sm:p-5">
                            <h2 className="text-lg font-semibold">Журнал изменений</h2>
                            <ol className="mt-3 divide-y divide-[var(--tf-line)]">
                                {ticket.events?.map((event) => (
                                    <li
                                        className="space-y-1 py-3 text-sm"
                                        key={event.id}
                                    >
                                        <strong className="block">
                                            {eventLabel[event.action] ?? event.action}
                                        </strong>
                                        <p className="text-[var(--tf-faint)]">
                                            {supportDate(event.created_at)} ·{' '}
                                            {event.actor_id === null
                                                ? 'участник удалён'
                                                : `${['created', 'user_reply'].includes(event.action) ? 'пользователь' : 'администратор'} #${event.actor_id}`}
                                            {event.access_entitlement_id
                                                ? ` · ${event.access_plan_code === 'basic' ? 'Basic' : event.access_plan_code} · запись #${event.access_entitlement_id}`
                                                : ''}
                                            {event.access_ends_at
                                                ? ` · срок до ${supportDate(event.access_ends_at)}`
                                                : ''}
                                        </p>
                                        {event.reason ? (
                                            <p className="whitespace-pre-wrap break-words">
                                                Причина: {event.reason}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ol>
                        </GlassCard>
                    </div>
                </div>
            </AppShell>
        </>
    );
}
