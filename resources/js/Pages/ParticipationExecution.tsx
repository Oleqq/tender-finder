import '../../css/proposals.css';
import '../../css/execution.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, Button, GlassCard, InlineAlert } from '../Components/ui';
import { useProposalAction } from '../lib/proposals';
import type { PageProps } from '../types';

type Expense = {
    id: string;
    title: string;
    category: string;
    actual: string | null;
    remaining: string | null;
    note: string | null;
    task_id: number | null;
};
type Execution = {
    version: number;
    baseline: Record<string, string | number | null>;
    baseline_changed: boolean;
    expenses: (Expense & { task_missing: boolean })[];
    received_revenue: string | null;
    remaining_revenue: string | null;
    costs_confirmed: boolean;
    summary: Record<string, string | boolean | null>;
    categories: {
        key: string;
        label: string;
        planned: string | null;
        actual: string | null;
        remaining: string | null;
        forecast: string | null;
        deviation: string | null;
        expense_ids: string[];
    }[];
};
type Props = {
    execution: Execution | null;
    tender: { id: number; title: string };
    team: { id: number; name: string } | null;
    stage: string;
    can_edit: boolean;
    economicsVersion: number;
    categories: Record<string, string>;
    source: Record<string, string | null>;
    tasks: { id: number; title: string }[];
};
const money = (value: string | number | boolean | null | undefined) =>
    typeof value === 'string' || typeof value === 'number'
        ? `${Number(value).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₽`
        : 'Не указано';
const editableRow = (row: Expense): Expense => ({
    id: row.id,
    title: row.title,
    category: row.category,
    actual: row.actual,
    remaining: row.remaining,
    note: row.note,
    task_id: row.task_id,
});

export default function ParticipationExecution() {
    const {
        execution: initial,
        tender,
        team,
        stage,
        can_edit,
        economicsVersion,
        categories,
        source,
        tasks,
    } = usePage<PageProps<Props>>().props;
    const [execution, setExecution] = useState(initial);
    const [rows, setRows] = useState<Expense[]>(
        initial?.expenses.map(editableRow) ?? [],
    );
    const [received, setReceived] = useState(initial?.received_revenue ?? '');
    const [remaining, setRemaining] = useState(initial?.remaining_revenue ?? '');
    const [confirmed, setConfirmed] = useState(initial?.costs_confirmed ?? false);
    const [startConfirmed, setStartConfirmed] = useState(false);
    const [dirty, setDirty] = useState(false);
    const action = useProposalAction();
    const suffix = team ? `?team_id=${team.id}` : '';
    const work = `/tenders/${tender.id}/work${suffix}`;
    const root = `/tenders/${tender.id}/execution${suffix}`;
    const readOnly = !can_edit || stage !== 'won';
    const disabled = readOnly || action.busy;
    function change(id: string, update: Partial<Expense>) {
        setRows(rows.map((row) => (row.id === id ? { ...row, ...update } : row)));
        setDirty(true);
        setConfirmed(false);
    }
    async function save(start = false) {
        const result = await action.run<{ execution: Execution }>(
            start ? 'post' : 'patch',
            root,
            start
                ? { economics_version: economicsVersion, confirmed: startConfirmed }
                : {
                      version: execution?.version,
                      expenses: rows,
                      received_revenue: received || null,
                      remaining_revenue: remaining || null,
                      costs_confirmed: confirmed,
                  },
            start
                ? 'Исходный план зафиксирован. Заполните факт и оставшиеся затраты.'
                : 'Исполнение сохранено. Прогноз пересчитан.',
        );
        if (result) {
            setExecution(result.execution);
            setRows(result.execution.expenses.map(editableRow));
            setReceived(result.execution.received_revenue ?? '');
            setRemaining(result.execution.remaining_revenue ?? '');
            setConfirmed(result.execution.costs_confirmed);
            setDirty(false);
        }
    }
    function amountInput(
        label: string,
        value: string | null,
        onChange: (value: string) => void,
    ) {
        return (
            <label>
                {label}
                <input
                    inputMode="decimal"
                    value={value ?? ''}
                    maxLength={13}
                    placeholder="Неизвестно"
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value)}
                />
            </label>
        );
    }
    return (
        <>
            <Head title="Контроль исполнения" />
            <AppShell
                title="Контроль исполнения"
                eyebrow="Ultra · прибыль по контракту"
                backHref={work}
            >
                <section className="proposal-intro">
                    <Badge tone="accent">
                        {team ? `Команда: ${team.name}` : 'Личная заявка'}
                    </Badge>
                    <h2>{tender.title}</h2>
                    <p>
                        Сравните исходный план с текущим прогнозом. Полученные деньги,
                        понесённые расходы и ожидания учитываются отдельно.
                    </p>
                    <Link href={work}>Экономика заявки и задачи →</Link>
                </section>
                {action.error ? (
                    <InlineAlert children={null} tone="danger" title={action.error} />
                ) : null}
                {action.message ? (
                    <InlineAlert
                        children={null}
                        tone="success"
                        title={action.message}
                    />
                ) : null}
                {readOnly ? (
                    <InlineAlert
                        children={null}
                        tone="warning"
                        title={
                            stage !== 'won'
                                ? 'Для редактирования исполнения переведите заявку на этап «Победа».'
                                : 'В этом рабочем пространстве доступен только просмотр.'
                        }
                    />
                ) : null}
                {!execution ? (
                    <GlassCard className="proposal-card proposal-form">
                        <h2>Зафиксируйте исходный план</h2>
                        <p>
                            Снимок экономики сохранится один раз. Последующие правки
                            экономики заявки его не изменят. Проверьте суммы сейчас;
                            пустые значения останутся неизвестными.
                        </p>
                        <dl className="execution-baseline">
                            {Object.entries({
                                planned_revenue: 'Цена предложения',
                                ...categories,
                            }).map(([key, label]) => (
                                <div key={key}>
                                    <dt>{label}</dt>
                                    <dd>{money(source[key])}</dd>
                                </div>
                            ))}
                        </dl>
                        <p>
                            Факт из общей экономики автоматически не переносится.
                            Введите его по статьям после запуска, чтобы не учитывать
                            одни и те же расходы дважды.
                        </p>
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={startConfirmed}
                                disabled={disabled}
                                onChange={(event) =>
                                    setStartConfirmed(event.target.checked)
                                }
                            />
                            <span>
                                Проверил исходный расчёт и хочу сохранить его для
                                сравнения.
                            </span>
                        </label>
                        <Button
                            disabled={disabled || !startConfirmed}
                            onClick={() => save(true)}
                        >
                            {action.busy ? 'Сохраняем…' : 'Начать контроль исполнения'}
                        </Button>
                    </GlassCard>
                ) : (
                    <>
                        {execution.baseline_changed ? (
                            <InlineAlert
                                children={null}
                                tone="warning"
                                title="Экономика заявки изменилась после запуска. Для сравнения сохранён первоначальный снимок."
                            />
                        ) : null}
                        {dirty ? (
                            <InlineAlert
                                children={null}
                                tone="warning"
                                title="Есть несохранённые правки. Показатели ниже относятся к последнему сохранению."
                            />
                        ) : null}
                        {execution.summary.result_change !== null &&
                        Number(execution.summary.result_change) < 0 ? (
                            <InlineAlert
                                tone="warning"
                                title={`Прогноз ниже исходного плана на ${money(Math.abs(Number(execution.summary.result_change)))}`}
                            >
                                <a href="#execution-changes">
                                    Проверить изменение выручки и статьи расходов ↓
                                </a>
                            </InlineAlert>
                        ) : null}
                        <GlassCard className="proposal-card">
                            <h2>Результат исполнения</h2>
                            <div className="execution-metrics">
                                <div>
                                    <span>Исходный план</span>
                                    <strong>
                                        {money(execution.summary.planned_result)}
                                    </strong>
                                </div>
                                <div>
                                    <span>Прогноз результата</span>
                                    <strong>
                                        {execution.summary.complete
                                            ? money(execution.summary.forecast_result)
                                            : 'Расчёт не завершён'}
                                    </strong>
                                </div>
                                <div>
                                    <span>Изменение к плану</span>
                                    <strong>
                                        {money(execution.summary.result_change)}
                                    </strong>
                                </div>
                                <div>
                                    <span>Получено минус потрачено</span>
                                    <strong>
                                        {money(execution.summary.cash_balance)}
                                    </strong>
                                </div>
                            </div>
                            <p>
                                Прогноз = полученная и оставшаяся выручка − фактические
                                и оставшиеся расходы. Это ожидаемый результат, а не
                                полученная прибыль. Денежный остаток не учитывает
                                будущие платежи и затраты.
                            </p>
                            {!execution.summary.complete ? (
                                <InlineAlert
                                    children={null}
                                    tone="warning"
                                    title="Укажите факт и остаток по всем статьям, выручку и подтвердите полноту расходов. Если затрат или поступлений нет, введите 0."
                                />
                            ) : null}
                            <details>
                                <summary>
                                    Исходные суммы ·{' '}
                                    {new Date(
                                        String(execution.baseline.captured_at),
                                    ).toLocaleString('ru-RU')}
                                </summary>
                                <dl className="execution-baseline">
                                    {Object.entries({
                                        planned_revenue: 'Цена предложения',
                                        ...categories,
                                    }).map(([key, label]) => (
                                        <div key={key}>
                                            <dt>{label}</dt>
                                            <dd>{money(execution.baseline[key])}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </details>
                            <dl className="execution-baseline">
                                {[
                                    ['Фактические расходы', 'actual_costs'],
                                    ['Оставшиеся расходы', 'remaining_costs'],
                                    ['Прогноз всех расходов', 'forecast_costs'],
                                    ['Прогноз всей выручки', 'forecast_revenue'],
                                ].map(([label, key]) => (
                                    <div key={key}>
                                        <dt>{label}</dt>
                                        <dd>{money(execution.summary[key])}</dd>
                                    </div>
                                ))}
                            </dl>
                        </GlassCard>
                        <GlassCard className="proposal-card">
                            <h2 id="execution-changes">Что изменилось</h2>
                            <p>
                                Отклонения расходов сравниваются по категориям с
                                исходным снимком. Плюс означает увеличение затрат;
                                пояснения и связанные задачи находятся в статьях ниже.
                            </p>
                            <p>
                                Изменение ожидаемой выручки:{' '}
                                <strong>
                                    {money(execution.summary.revenue_change)}
                                </strong>
                            </p>
                            {execution.categories.map((category) => (
                                <div className="execution-category" key={category.key}>
                                    <strong>{category.label}</strong>
                                    <span>
                                        План {money(category.planned)} · прогноз{' '}
                                        {money(category.forecast)}
                                    </span>
                                    <span
                                        className={
                                            Number(category.deviation) > 0
                                                ? 'execution-overrun'
                                                : ''
                                        }
                                    >
                                        Отклонение: {money(category.deviation)}
                                    </span>
                                    <div className="proposal-actions">
                                        {category.expense_ids.map((id) => (
                                            <a key={id} href={`#expense-${id}`}>
                                                {
                                                    execution.expenses.find(
                                                        (row) => row.id === id,
                                                    )?.title
                                                }{' '}
                                                ↓
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </GlassCard>
                        <form
                            className="proposal-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void save();
                            }}
                        >
                            <GlassCard className="proposal-card">
                                <h2>Поступления, ₽</h2>
                                <div className="execution-grid">
                                    {amountInput('Уже получено', received, (value) => {
                                        setReceived(value);
                                        setDirty(true);
                                    })}
                                    {amountInput(
                                        'Осталось получить',
                                        remaining,
                                        (value) => {
                                            setRemaining(value);
                                            setDirty(true);
                                        },
                                    )}
                                </div>
                                <p>
                                    В остаток внесите только будущие поступления. Все
                                    суммы в рублях, до копеек. Пустое поле означает
                                    «неизвестно».
                                </p>
                            </GlassCard>
                            <h2 className="execution-heading">Расходы и работы</h2>
                            {rows.map((row, index) => (
                                <div
                                    key={row.id}
                                    id={`expense-${row.id}`}
                                    className="execution-expense"
                                >
                                    <GlassCard className="proposal-card">
                                        <h3>
                                            Статья {index + 1} ·{' '}
                                            {row.title || 'Без названия'}
                                        </h3>
                                        <label>
                                            Название
                                            <input
                                                required
                                                maxLength={160}
                                                value={row.title}
                                                disabled={disabled}
                                                onChange={(event) =>
                                                    change(row.id, {
                                                        title: event.target.value,
                                                    })
                                                }
                                            />
                                        </label>
                                        <div className="execution-grid">
                                            <label>
                                                Категория
                                                <select
                                                    value={row.category}
                                                    disabled={disabled}
                                                    onChange={(event) =>
                                                        change(row.id, {
                                                            category:
                                                                event.target.value,
                                                        })
                                                    }
                                                >
                                                    {Object.entries(categories).map(
                                                        ([key, label]) => (
                                                            <option
                                                                key={key}
                                                                value={key}
                                                            >
                                                                {label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </label>
                                            <label>
                                                Связанная задача
                                                <select
                                                    value={row.task_id ?? ''}
                                                    disabled={disabled}
                                                    onChange={(event) =>
                                                        change(row.id, {
                                                            task_id: event.target.value
                                                                ? Number(
                                                                      event.target
                                                                          .value,
                                                                  )
                                                                : null,
                                                        })
                                                    }
                                                >
                                                    <option value="">Без задачи</option>
                                                    {row.task_id &&
                                                    !tasks.some(
                                                        (task) =>
                                                            task.id === row.task_id,
                                                    ) ? (
                                                        <option value={row.task_id}>
                                                            Задача удалена
                                                        </option>
                                                    ) : null}
                                                    {tasks.map((task) => (
                                                        <option
                                                            key={task.id}
                                                            value={task.id}
                                                        >
                                                            {task.title}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>
                                        </div>
                                        {row.task_id &&
                                        tasks.some(
                                            (task) => task.id === row.task_id,
                                        ) ? (
                                            <a href={`${work}#task-${row.task_id}`}>
                                                Открыть связанную задачу →
                                            </a>
                                        ) : null}
                                        <div className="execution-grid">
                                            {amountInput(
                                                'Уже потрачено, ₽',
                                                row.actual,
                                                (value) =>
                                                    change(row.id, { actual: value }),
                                            )}
                                            {amountInput(
                                                'Осталось потратить, ₽',
                                                row.remaining,
                                                (value) =>
                                                    change(row.id, {
                                                        remaining: value,
                                                    }),
                                            )}
                                        </div>
                                        <label>
                                            Что входит в сумму / причина изменения
                                            <textarea
                                                rows={2}
                                                maxLength={2000}
                                                value={row.note ?? ''}
                                                disabled={disabled}
                                                onChange={(event) =>
                                                    change(row.id, {
                                                        note: event.target.value,
                                                    })
                                                }
                                                placeholder="Например: дополнительные 20 часов подрядчика по 1 800 ₽"
                                            />
                                        </label>
                                        {rows.filter(
                                            (item) => item.category === row.category,
                                        ).length > 1 ? (
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                disabled={disabled}
                                                onClick={() => {
                                                    setRows(
                                                        rows.filter(
                                                            (item) =>
                                                                item.id !== row.id,
                                                        ),
                                                    );
                                                    setConfirmed(false);
                                                    setDirty(true);
                                                }}
                                            >
                                                Убрать статью из расчёта
                                            </Button>
                                        ) : null}
                                    </GlassCard>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={disabled || rows.length >= 100}
                                onClick={() => {
                                    setRows([
                                        ...rows,
                                        {
                                            id: crypto.randomUUID(),
                                            title: '',
                                            category: 'planned_cost',
                                            actual: null,
                                            remaining: null,
                                            note: null,
                                            task_id: null,
                                        },
                                    ]);
                                    setConfirmed(false);
                                    setDirty(true);
                                }}
                            >
                                Добавить расход или трудозатраты
                            </Button>
                            <GlassCard className="proposal-card">
                                <label className="proposal-check">
                                    <input
                                        type="checkbox"
                                        checked={confirmed}
                                        disabled={disabled}
                                        onChange={(event) => {
                                            setConfirmed(event.target.checked);
                                            setDirty(true);
                                        }}
                                    />
                                    <span>
                                        Учёл все фактические и оставшиеся расходы,
                                        включая трудозатраты, налоги и дополнительные
                                        работы.
                                        <small>
                                            При изменении статей подтвердите полноту
                                            заново. Нулевые суммы указывайте явно.
                                        </small>
                                    </span>
                                </label>
                                <Button type="submit" disabled={disabled || !dirty}>
                                    {action.busy
                                        ? 'Сохраняем…'
                                        : 'Сохранить и пересчитать'}
                                </Button>
                                {action.error ? (
                                    <InlineAlert
                                        children={null}
                                        tone="danger"
                                        title={action.error}
                                    />
                                ) : null}
                                {action.message ? (
                                    <InlineAlert
                                        children={null}
                                        tone="success"
                                        title={action.message}
                                    />
                                ) : null}
                            </GlassCard>
                        </form>
                    </>
                )}
            </AppShell>
        </>
    );
}
