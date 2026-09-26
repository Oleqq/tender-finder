import { scopedUrl } from '../lib/workspace';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import { Badge, Button, GlassCard } from '../Components/ui';
import { WorkspacePicker, type TeamScope } from '../Components/WorkspacePicker';
import type { PageProps } from '../types';

type CalendarEvent = {
    id: string;
    tender_id: number;
    title: string;
    tender_title: string;
    kind: string;
    date: string;
    starts_at: string;
    all_day: boolean;
    url: string;
};
type CalendarSubscription = {
    active: boolean;
    url: string | null;
    created_at: string | null;
};
const kinds = [
    { value: 'all', label: 'Все события' },
    { value: 'deadline', label: 'Подача заявок' },
    { value: 'action', label: 'Личные действия' },
    { value: 'task', label: 'Задачи' },
];

export default function TenderCalendar() {
    const {
        month,
        timezone,
        events,
        team,
        subscription: initialSubscription,
    } = usePage<
        PageProps<
            TeamScope & {
                month: string;
                timezone: string;
                events: CalendarEvent[];
                subscription: CalendarSubscription;
            }
        >
    >().props;
    const [selected, setSelected] = useState<string | null>(null);
    const [kind, setKind] = useState('all');
    const [subscription, setSubscription] = useState(initialSubscription);
    const [subscriptionBusy, setSubscriptionBusy] = useState(false);
    const [subscriptionError, setSubscriptionError] = useState('');
    const [subscriptionNotice, setSubscriptionNotice] = useState('');
    const [year, number] = month.split('-').map(Number);
    const first = new Date(Date.UTC(year, number - 1, 1));
    const offset = (first.getUTCDay() + 6) % 7;
    const days = new Date(Date.UTC(year, number, 0)).getUTCDate();
    const matching = events.filter((event) => kind === 'all' || event.kind === kind);
    const agenda = matching.filter((event) => !selected || event.date === selected);
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
    const move = (delta: number): void => {
        const date = new Date(Date.UTC(year, number - 1 + delta, 1));
        router.get(scopedUrl('/calendar', team), {
            month: date.toISOString().slice(0, 7),
        });
    };
    const rotateSubscription = async (): Promise<void> => {
        if (
            subscription.active &&
            !window.confirm(
                'Перевыпустить ссылку? Прежняя подписка календаря сразу перестанет работать.',
            )
        )
            return;
        setSubscriptionBusy(true);
        setSubscriptionError('');
        setSubscriptionNotice('');
        try {
            const response = await window.axios.post<{
                subscription: CalendarSubscription;
            }>(scopedUrl('/calendar/subscription', team));
            setSubscription(response.data.subscription);
            setSubscriptionNotice(
                subscription.active
                    ? 'Ссылка перевыпущена. Обновите её в календаре.'
                    : 'Приватная ссылка создана.',
            );
        } catch {
            setSubscriptionError('Не удалось создать ссылку. Попробуйте ещё раз.');
        } finally {
            setSubscriptionBusy(false);
        }
    };
    const revokeSubscription = async (): Promise<void> => {
        if (!window.confirm('Отключить обновляемую подписку календаря?')) return;
        setSubscriptionBusy(true);
        setSubscriptionError('');
        setSubscriptionNotice('');
        try {
            const response = await window.axios.delete<{
                subscription: CalendarSubscription;
            }>(scopedUrl('/calendar/subscription', team));
            setSubscription(response.data.subscription);
            setSubscriptionNotice('Ссылка отключена.');
        } catch {
            setSubscriptionError('Не удалось отключить ссылку. Попробуйте ещё раз.');
        } finally {
            setSubscriptionBusy(false);
        }
    };
    const copySubscription = async (): Promise<void> => {
        if (!subscription.url) return;
        try {
            await navigator.clipboard.writeText(subscription.url);
            setSubscriptionNotice('Ссылка скопирована.');
            setSubscriptionError('');
        } catch {
            setSubscriptionError(
                'Не удалось скопировать автоматически. Выделите ссылку вручную.',
            );
        }
    };
    return (
        <>
            <Head title="Календарь закупок" />
            <AppShell title="Календарь" activeNav="/tenders" className="work-page">
                <TenderWorkNav active="/calendar" />
                <WorkspacePicker path="/calendar" />
                <div className="work-intro">
                    <h2>Сроки под контролем</h2>
                    <p>
                        Подача заявок, личные действия и незавершённые задачи. Часовой
                        пояс: {timezone}.
                    </p>
                </div>
                <GlassCard className="work-card">
                    <div className="work-toolbar">
                        <Button
                            variant="ghost"
                            onClick={() => move(-1)}
                            aria-label="Предыдущий месяц"
                            disabled={month === '2000-01'}
                        >
                            ←
                        </Button>
                        <h2>
                            {first.toLocaleDateString('ru-RU', {
                                month: 'long',
                                year: 'numeric',
                                timeZone: 'UTC',
                            })}
                        </h2>
                        <Button
                            variant="ghost"
                            onClick={() => move(1)}
                            aria-label="Следующий месяц"
                            disabled={month === '2099-12'}
                        >
                            →
                        </Button>
                    </div>
                    <label className="work-month-label">
                        Выбрать месяц
                        <input
                            type="month"
                            min="2000-01"
                            max="2099-12"
                            value={month}
                            onChange={(e) => {
                                if (e.target.value)
                                    router.get(scopedUrl('/calendar', team), {
                                        month: e.target.value,
                                    });
                            }}
                        />
                    </label>
                    <div className="work-calendar" aria-label="Дни месяца">
                        {['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'].map((label) => (
                            <span className="work-weekday" key={label}>
                                {label}
                            </span>
                        ))}
                        {Array.from({ length: offset }, (_, i) => (
                            <span key={`blank-${i}`} />
                        ))}
                        {Array.from({ length: days }, (_, i) => {
                            const date = `${month}-${String(i + 1).padStart(2, '0')}`;
                            const count = matching.filter(
                                (event) => event.date === date,
                            ).length;
                            return (
                                <button
                                    type="button"
                                    key={date}
                                    onClick={() => setSelected(date)}
                                    aria-pressed={selected === date}
                                    aria-label={`${i + 1}, событий: ${count}`}
                                    aria-current={today === date ? 'date' : undefined}
                                    className={`${selected === date ? 'is-selected' : ''} ${today === date ? 'is-today' : ''}`}
                                >
                                    <span>{i + 1}</span>
                                    {count ? <small>{count}</small> : null}
                                </button>
                            );
                        })}
                    </div>
                    <div className="work-actions">
                        <Button
                            onClick={() => setSelected(null)}
                            variant="secondary"
                            disabled={!selected}
                        >
                            Весь месяц
                        </Button>
                        <a
                            className="button button--secondary"
                            href={scopedUrl(`/calendar/export?month=${month}`, team)}
                        >
                            Скачать месяц .ics
                        </a>
                    </div>
                    <p className="work-help">
                        Файл содержит все события месяца. Это снимок календаря:
                        изменения и удалённые задачи не синхронизируются автоматически.
                    </p>
                </GlassCard>
                <GlassCard className="work-card calendar-subscription">
                    <div>
                        <h2>Обновляемая подписка</h2>
                        <p>
                            Добавьте приватную ссылку в Google Calendar, Apple Calendar
                            или другое приложение. Сроки обновляются при следующей
                            синхронизации календаря.
                        </p>
                    </div>
                    {subscription.url ? (
                        <label className="work-subscription-url">
                            Приватная ICS-ссылка
                            <input
                                readOnly
                                value={subscription.url}
                                onFocus={(event) => event.currentTarget.select()}
                            />
                        </label>
                    ) : (
                        <p>Активной ссылки пока нет.</p>
                    )}
                    <p className="work-help">
                        Ссылка открывает названия и сроки доступных вам заявок без
                        входа. Не публикуйте её и перевыпустите при подозрении на
                        утечку. Лента включает события с прошлого месяца на 18 месяцев
                        вперёд.
                    </p>
                    {subscriptionNotice ? (
                        <p className="work-success">{subscriptionNotice}</p>
                    ) : null}
                    {subscriptionError ? (
                        <p className="work-error">{subscriptionError}</p>
                    ) : null}
                    <div className="work-actions">
                        {subscription.active ? (
                            <>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={subscriptionBusy}
                                    onClick={() => void copySubscription()}
                                >
                                    Скопировать ссылку
                                </Button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={subscriptionBusy}
                                    onClick={() => void rotateSubscription()}
                                >
                                    Перевыпустить
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={subscriptionBusy}
                                    onClick={() => void revokeSubscription()}
                                >
                                    Отключить
                                </Button>
                            </>
                        ) : (
                            <Button
                                type="button"
                                disabled={subscriptionBusy}
                                onClick={() => void rotateSubscription()}
                            >
                                {subscriptionBusy
                                    ? 'Создаём…'
                                    : 'Создать приватную ссылку'}
                            </Button>
                        )}
                    </div>
                </GlassCard>
                <div className="work-form">
                    <label>
                        Показать
                        <select value={kind} onChange={(e) => setKind(e.target.value)}>
                            {kinds.map((k) => (
                                <option key={k.value} value={k.value}>
                                    {k.label}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <section
                    className="work-list"
                    aria-label="События календаря"
                    aria-live="polite"
                >
                    <h2>
                        {selected
                            ? selected.split('-').reverse().join('.')
                            : 'События месяца'}{' '}
                        · {agenda.length}
                    </h2>
                    {agenda.length === 0 ? (
                        <GlassCard className="work-card">
                            <p>
                                На выбранный период событий нет. Задайте дату личного
                                действия в ленте или срок задачи в чек-листе.
                            </p>
                        </GlassCard>
                    ) : null}
                    {agenda.map((event) => (
                        <GlassCard key={event.id} className="work-card">
                            <Badge
                                tone={event.kind === 'deadline' ? 'warning' : 'accent'}
                            >
                                {kinds.find((k) => k.value === event.kind)?.label}
                            </Badge>
                            <time dateTime={event.starts_at}>
                                {event.date.split('-').reverse().join('.')}
                                {event.all_day
                                    ? ' · весь день'
                                    : ` · ${new Date(event.starts_at).toLocaleTimeString('ru-RU', { timeZone: timezone, hour: '2-digit', minute: '2-digit' })}`}
                            </time>
                            <h3>{event.title}</h3>
                            <Link href={event.url}>{event.tender_title}</Link>
                        </GlassCard>
                    ))}
                </section>
            </AppShell>
        </>
    );
}
