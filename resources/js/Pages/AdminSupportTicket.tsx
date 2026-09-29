import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, GlassCard, InlineAlert } from '../Components/ui';
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

export default function AdminSupportTicket() {
    const { ticket, diagnostics, assignees } = usePage<
        PageProps<{
            ticket: SupportTicketDetail;
            diagnostics: Diagnostics;
            assignees: Array<{ id: number; name: string | null }>;
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

    return (
        <>
            <Head title={`Поддержка #${ticket.id}`} />
            <AppShell
                backHref="/support/admin"
                eyebrow={`Пользователь #${ticket.user_id}`}
                title={`Обращение #${ticket.id}`}
                wide
            >
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge>{categoryLabel[ticket.category]}</Badge>
                        <Badge>{statusLabel[ticket.status]}</Badge>
                    </div>
                    <ol className="space-y-3">
                        {ticket.messages.map((message) => (
                            <li key={message.id}>
                                <GlassCard tone={message.is_staff ? 'accent' : 'quiet'}>
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
                    <GlassCard>
                        <h2 className="text-lg font-semibold">Ответ пользователю</h2>
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
                    <GlassCard>
                        <h2 className="text-lg font-semibold">Работа с обращением</h2>
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
                                    {Object.entries(statusLabel).map(([key, label]) => (
                                        <option key={key} value={key}>
                                            {label}
                                        </option>
                                    ))}
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
                                        <option key={assignee.id} value={assignee.id}>
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
                                        workflow.setData('reason', event.target.value)
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
                        Здесь нет поисковых фраз, карточек тендеров, payload уведомлений
                        и технических секретов. Изменение доступа на этом экране
                        недоступно.
                    </InlineAlert>
                    <GlassCard>
                        <h2 className="text-lg font-semibold">Доступ</h2>
                        <p>Состояние: {diagnostics.access.state}</p>
                        <p>Тариф: {diagnostics.access.plan_code ?? 'не назначен'}</p>
                        <p>
                            До:{' '}
                            {diagnostics.access.ends_at
                                ? supportDate(diagnostics.access.ends_at)
                                : 'без даты'}
                        </p>
                    </GlassCard>
                    <GlassCard>
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
                    <GlassCard>
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
                    <GlassCard>
                        <h2 className="text-lg font-semibold">Журнал изменений</h2>
                        <ol className="mt-3 space-y-2">
                            {ticket.events?.map((event) => (
                                <li key={event.id}>
                                    {supportDate(event.created_at)} · {event.action} ·
                                    администратор #{event.actor_id ?? 'удалён'}
                                    {event.reason ? ` · ${event.reason}` : ''}
                                </li>
                            ))}
                        </ol>
                    </GlassCard>
                </div>
            </AppShell>
        </>
    );
}
