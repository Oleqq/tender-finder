import { Head, router, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, FieldError, GlassCard } from '../Components/ui';
import {
    categoryLabel,
    statusLabel,
    supportDate,
    type SupportTicketDetail,
} from '../lib/support';
import type { PageProps } from '../types';

export default function SupportTicket() {
    const { ticket } = usePage<PageProps<{ ticket: SupportTicketDetail }>>().props;
    const form = useForm({ body: '' });

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        form.post(`/support/${ticket.id}/reply`, {
            onSuccess: () => form.reset(),
        });
    };

    return (
        <>
            <Head title={`Обращение #${ticket.id}`} />
            <AppShell
                className="support-page"
                activeNav="/profile"
                backHref="/support"
                eyebrow={categoryLabel[ticket.category]}
                title={`Обращение #${ticket.id}`}
            >
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <Badge>{statusLabel[ticket.status]}</Badge>
                        <Button
                            onClick={() => router.reload({ only: ['ticket'] })}
                            size="sm"
                            variant="secondary"
                        >
                            Проверить ответ
                        </Button>
                    </div>
                    <p className="text-sm text-[var(--app-muted)]">
                        {ticket.status === 'resolved'
                            ? 'Обращение решено. Если проблема осталась, добавьте уточнение.'
                            : 'Ответ поддержки появится здесь. Вы можете добавить уточнение.'}
                    </p>
                    <ol className="space-y-3">
                        {ticket.messages.map((message) => (
                            <li key={message.id}>
                                <GlassCard
                                    className="support-card"
                                    tone={message.is_staff ? 'accent' : 'quiet'}
                                >
                                    <strong>
                                        {message.is_staff ? 'Поддержка' : 'Вы'}
                                    </strong>
                                    <p className="mt-2 whitespace-pre-wrap break-words">
                                        {message.body}
                                    </p>
                                    <small>{supportDate(message.created_at)}</small>
                                </GlassCard>
                            </li>
                        ))}
                    </ol>
                    <GlassCard className="support-card">
                        <form className="space-y-3" onSubmit={submit}>
                            <label className="form-field">
                                <span>Ответить</span>
                                <textarea
                                    maxLength={2000}
                                    minLength={2}
                                    onChange={(event) =>
                                        form.setData('body', event.target.value)
                                    }
                                    required
                                    rows={4}
                                    value={form.data.body}
                                />
                            </label>
                            {form.errors.body ? (
                                <FieldError>{form.errors.body}</FieldError>
                            ) : null}
                            <Button
                                disabled={
                                    form.processing || form.data.body.trim().length < 2
                                }
                                type="submit"
                            >
                                Отправить
                            </Button>
                        </form>
                    </GlassCard>
                </div>
            </AppShell>
        </>
    );
}
