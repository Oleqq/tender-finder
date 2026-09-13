import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import { Badge, Button, GlassCard } from '../Components/ui';
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
const kinds = [
    { value: 'all', label: 'Все события' },
    { value: 'deadline', label: 'Подача заявок' },
    { value: 'action', label: 'Личные действия' },
    { value: 'task', label: 'Задачи' },
];

export default function TenderCalendar() {
    const { month, timezone, events } =
        usePage<
            PageProps<{ month: string; timezone: string; events: CalendarEvent[] }>
        >().props;
    const [selected, setSelected] = useState<string | null>(null);
    const [kind, setKind] = useState('all');
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
        router.get('/calendar', { month: date.toISOString().slice(0, 7) });
    };
    return (
        <>
            <Head title="Календарь закупок" />
            <AppShell title="Календарь" activeNav="/tenders" className="work-page">
                <TenderWorkNav active="/calendar" />
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
                                    router.get('/calendar', { month: e.target.value });
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
                            href={`/calendar/export?month=${month}`}
                        >
                            Скачать месяц .ics
                        </a>
                    </div>
                    <p className="work-help">
                        Файл содержит все события месяца. Это снимок календаря:
                        изменения и удалённые задачи не синхронизируются автоматически.
                    </p>
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
                            <Link href={`/tenders/${event.tender_id}/work`}>
                                {event.tender_title}
                            </Link>
                        </GlassCard>
                    ))}
                </section>
            </AppShell>
        </>
    );
}
