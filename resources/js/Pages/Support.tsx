import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, FieldError, GlassCard, InlineAlert } from '../Components/ui';
import {
    categoryLabel,
    statusLabel,
    supportDate,
    type SupportCategory,
    type SupportTicketSummary,
} from '../lib/support';
import type { PageProps } from '../types';

export default function Support() {
    const page = usePage<PageProps<{ tickets: SupportTicketSummary[] }>>();
    const { tickets } = page.props;
    const paymentIssue =
        new URL(page.url, window.location.origin).searchParams.get('topic') ===
        'payment-access';
    const form = useForm<{ category: SupportCategory; body: string }>({
        category: 'access',
        body: '',
    });

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        form.post('/support');
    };

    return (
        <>
            <Head title="Поддержка" />
            <AppShell
                className="support-page"
                activeNav="/profile"
                backHref="/profile"
                eyebrow="Помощь"
                title="Поддержка"
            >
                <div className="space-y-5">
                    {paymentIssue ? (
                        <InlineAlert title="Оплата прошла, доступа нет" tone="warning">
                            Укажите дату, время и доступный идентификатор заказа.
                            Поддержка проверит платёж и состояние доступа. Не
                            отправляйте реквизиты карты.
                        </InlineAlert>
                    ) : null}
                    <InlineAlert title="Опишите проблему" tone="neutral">
                        Не добавляйте пароли, токены, поисковые фразы и содержимое
                        тендеров. Укажите, что не работает и когда вы это заметили.
                    </InlineAlert>
                    <GlassCard className="support-card">
                        <h2 className="text-lg font-semibold">Новое обращение</h2>
                        <form className="mt-4 space-y-4" onSubmit={submit}>
                            <label className="form-field">
                                <span>Тема</span>
                                <select
                                    onChange={(event) =>
                                        form.setData(
                                            'category',
                                            event.target.value as SupportCategory,
                                        )
                                    }
                                    value={form.data.category}
                                >
                                    {Object.entries(categoryLabel).map(
                                        ([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label className="form-field">
                                <span>Описание</span>
                                <textarea
                                    maxLength={2000}
                                    minLength={20}
                                    onChange={(event) =>
                                        form.setData('body', event.target.value)
                                    }
                                    required
                                    rows={5}
                                    placeholder={
                                        paymentIssue
                                            ? 'Когда прошла оплата? Укажите идентификатор заказа и что видите на экране доступа.'
                                            : undefined
                                    }
                                    value={form.data.body}
                                />
                            </label>
                            {form.errors.body ? (
                                <FieldError>{form.errors.body}</FieldError>
                            ) : null}
                            <p className="work-help">
                                Опишите проблему минимум в 20 символах.
                            </p>
                            <Button
                                disabled={
                                    form.processing || form.data.body.trim().length < 20
                                }
                                type="submit"
                            >
                                Отправить обращение
                            </Button>
                        </form>
                    </GlassCard>
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold">Мои обращения</h2>
                        {tickets.length === 0 ? <p>Пока нет обращений.</p> : null}
                        {tickets.map((ticket) => (
                            <GlassCard className="support-card" key={ticket.id}>
                                <Link
                                    className="block space-y-2"
                                    href={`/support/${ticket.id}`}
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <strong>
                                            #{ticket.id} ·{' '}
                                            {categoryLabel[ticket.category]}
                                        </strong>
                                        <Badge>{statusLabel[ticket.status]}</Badge>
                                    </div>
                                    <small>
                                        Обновлено {supportDate(ticket.updated_at)}
                                    </small>
                                </Link>
                            </GlassCard>
                        ))}
                    </section>
                </div>
            </AppShell>
        </>
    );
}
