import '../../css/cost-gap.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import {
    gapCategories,
    gapMoney,
    gapSum,
    gapStatuses,
    useGapAction,
    type Evidence,
    type GapCategory,
    type GapCheck,
    type GapDecision,
    type GapRow,
    type GapSource,
    type GapStatus,
} from '../lib/cost-gap';
import type { PageProps } from '../types';

type SourceDraft = {
    mode: 'keep' | 'text' | 'file' | 'document';
    text: string;
    file: File | null;
    document: string;
};
type Props = {
    tender: { id: number; title: string; fingerprint: string };
    check: GapCheck;
    documents: { id: number; title: string }[];
    teamId: number | null;
    canEdit: boolean;
};
const sourceDraft = (source: GapSource | undefined): SourceDraft => ({
    mode: source ? 'keep' : 'text',
    text: source?.kind === 'text' ? source.segments.map((s) => s.text).join('\n') : '',
    file: null,
    document: '',
});

export default function CostGapCheckPage() {
    const {
        tender,
        check: initial,
        documents,
        teamId,
        canEdit,
    } = usePage<PageProps<Props>>().props;
    const [check, setCheck] = useState(initial);
    const [customer, setCustomer] = useState(() =>
        sourceDraft(initial.sources?.customer),
    );
    const [supplier, setSupplier] = useState(() =>
        sourceDraft(initial.sources?.supplier),
    );
    const [manual, setManual] = useState(initial.manual_requirements);
    const [confirmed, setConfirmed] = useState(false);
    const [replace, setReplace] = useState(false);
    const [transferKey, setTransferKey] = useState<string | null>(null);
    const [category, setCategory] = useState<GapCategory>('other_cost');
    const [newExpense, setNewExpense] = useState(false);
    const [filter, setFilter] = useState<GapStatus | 'all'>('all');
    const action = useGapAction();
    const suffix = teamId ? `?team_id=${teamId}` : '';
    const root = `/tenders/${tender.id}/cost-check`;
    const workUrl = `/tenders/${tender.id}/work${suffix}`;
    const writable = canEdit && !action.busy;
    const current = writable && check.stale.length === 0;
    const version = () => ({ version: check.version, tender_hash: tender.fingerprint });
    const transferring = check.rows.find((r) => r.key === transferKey);
    const decision = transferKey ? check.decisions[transferKey] : null;

    async function analyze() {
        const data = new FormData();
        data.set('version', String(check.version));
        data.set('tender_hash', tender.fingerprint);
        data.set('confirmed', confirmed ? '1' : '0');
        data.set('manual_requirements', manual);
        for (const [side, draft] of [
            ['customer', customer],
            ['supplier', supplier],
        ] as const) {
            data.set(`${side}_mode`, draft.mode);
            if (draft.mode === 'text') data.set(`${side}_text`, draft.text);
            if (draft.mode === 'file' && draft.file)
                data.set(`${side}_file`, draft.file);
            if (draft.mode === 'document') data.set(`${side}_document`, draft.document);
        }
        const result = await action.run(
            'post',
            root + '/analyze' + suffix,
            data,
            'Сравнение сохранено. Проверьте цитаты и подтвердите выводы.',
        );
        if (result) {
            setCheck(result);
            setCustomer(sourceDraft(result.sources?.customer));
            setSupplier(sourceDraft(result.sources?.supplier));
            setConfirmed(false);
            setReplace(false);
        }
    }
    async function review(key: string, value: GapDecision) {
        const result = await action.run(
            'patch',
            root + '/review' + suffix,
            { ...version(), key, ...value, confirmed: true },
            'Вывод сохранён.',
        );
        if (result) setCheck(result);
        return result?.decisions[key] ?? null;
    }
    async function transfer() {
        const result = await action.run(
            'post',
            root + '/transfer' + suffix,
            {
                ...version(),
                key: transferKey,
                category,
                economics_version: check.economics.version,
                confirmed_new_expense: newExpense,
            },
            'Расход добавлен в плановую экономику заявки. Повторное добавление этого пункта заблокировано.',
        );
        if (result) {
            setCheck(result);
            setTransferKey(null);
            setNewExpense(false);
        }
    }
    const feedback = (
        <>
            {action.error ? (
                <div role="alert">
                    <InlineAlert title="Не удалось продолжить" tone="danger">
                        {action.error}
                    </InlineAlert>
                    {action.conflict ? (
                        <p>
                            Обновите страницу после сохранения копии введённого текста.
                            Несохранённые поля при обновлении будут потеряны.
                        </p>
                    ) : null}
                </div>
            ) : null}
            {action.message ? <p role="status">{action.message}</p> : null}
        </>
    );

    return (
        <>
            <Head title="Что забыли включить в стоимость" />
            <AppShell
                title="Проверка расходов"
                eyebrow="Pro / Ultra · PRO-02"
                backHref={workUrl}
            >
                <div className="cost-gap">
                    <header className="cost-gap__intro">
                        <Badge tone="accent">Что забыли включить в стоимость</Badge>
                        <h2>{tender.title}</h2>
                        <p>
                            Сравните обязательства заказчика с вашим предложением.
                            Найдите расходы, которые стоит уточнить до подачи заявки.
                        </p>
                    </header>
                    {!canEdit ? (
                        <InlineAlert title="Только просмотр">
                            Изменения доступны редакторам действующей команды с Pro или
                            Ultra.
                        </InlineAlert>
                    ) : null}
                    {check.stale.length > 0 ? (
                        <InlineAlert title="Материалы изменились" tone="warning">
                            {check.stale.join(' ')} Решения и перенос расходов
                            приостановлены.
                        </InlineAlert>
                    ) : null}
                    <GlassCard className="cost-gap__card">
                        <h2>1. Исходные материалы</h2>
                        <p>
                            PDF с текстовым слоем, DOCX или TXT до 5 МБ; до 40 000
                            знаков на источник. Сканы без текста не распознаются. Можно
                            вставить выдержки вручную.
                        </p>
                        <fieldset disabled={!writable} className="cost-gap__form">
                            <div className="cost-gap__sources">
                                <SourceInput
                                    title="Требования заказчика"
                                    value={customer}
                                    onChange={(value) => {
                                        setCustomer(value);
                                        setConfirmed(false);
                                    }}
                                    source={check.sources?.customer}
                                    documents={documents}
                                />
                                <SourceInput
                                    title="Предложение поставщика"
                                    value={supplier}
                                    onChange={(value) => {
                                        setSupplier(value);
                                        setConfirmed(false);
                                    }}
                                    source={check.sources?.supplier}
                                    documents={documents}
                                />
                            </div>
                            <label>
                                Дополнительные требования для проверки
                                <textarea
                                    rows={3}
                                    maxLength={15000}
                                    value={manual}
                                    placeholder="Одно требование на строку, например: ежедневная уборка рабочего места"
                                    onChange={(e) => {
                                        setManual(e.target.value);
                                        setConfirmed(false);
                                    }}
                                />
                            </label>
                            <label className="cost-gap__check">
                                <input
                                    type="checkbox"
                                    checked={confirmed}
                                    onChange={(e) => setConfirmed(e.target.checked)}
                                />
                                Я проверил, что выбраны актуальные требования и
                                предложение. Полноту выдержек проверю самостоятельно.
                            </label>
                            <Button
                                disabled={!confirmed}
                                onClick={() => {
                                    if (check.version) setReplace(true);
                                    else void analyze();
                                }}
                            >
                                {action.busy
                                    ? 'Обработка…'
                                    : check.version
                                      ? 'Повторить сравнение'
                                      : 'Сравнить обязательства'}
                            </Button>
                        </fieldset>
                    </GlassCard>
                    {!replace && !transferring ? feedback : null}
                    {check.version > 0 ? (
                        <>
                            <section
                                className="cost-gap__results"
                                aria-label="Результат сравнения"
                            >
                                <h2>2. Проверьте выводы</h2>
                                <p>
                                    Предварительный поиск по формулировкам, без ИИ.
                                    Совпадение названия не проверяет объём, сроки и все
                                    условия. Деньги вводите только после собственной
                                    проверки. Отсутствие упоминания не доказывает
                                    отсутствие услуги.
                                </p>
                                <div
                                    className="cost-gap__filters"
                                    aria-label="Фильтр результатов"
                                >
                                    {(
                                        [
                                            'all',
                                            'not_mentioned',
                                            'clarify',
                                            'included',
                                        ] as const
                                    ).map((status) => (
                                        <button
                                            type="button"
                                            key={status}
                                            aria-pressed={filter === status}
                                            onClick={() => setFilter(status)}
                                        >
                                            {status === 'all'
                                                ? 'Все'
                                                : gapStatuses[status]}{' '}
                                            <strong>
                                                {
                                                    check.rows.filter(
                                                        (r) =>
                                                            status === 'all' ||
                                                            (check.decisions[r.key]
                                                                ?.status ??
                                                                r.status) === status,
                                                    ).length
                                                }
                                            </strong>
                                        </button>
                                    ))}
                                </div>
                                {check.rows.length === 0 ? (
                                    <InlineAlert title="Требования не выделены">
                                        Проверьте извлечённый текст ниже и добавьте
                                        обязательства в дополнительный перечень. Пустой
                                        результат не означает, что предложение полное.
                                    </InlineAlert>
                                ) : null}
                                {check.rows
                                    .filter(
                                        (r) =>
                                            filter === 'all' ||
                                            (check.decisions[r.key]?.status ??
                                                r.status) === filter,
                                    )
                                    .map((row) => (
                                        <RequirementCard
                                            key={`${check.source_hash}:${row.key}`}
                                            row={row}
                                            decision={check.decisions[row.key]}
                                            transferred={check.transfers.some(
                                                (t) => t.key === row.key,
                                            )}
                                            canEdit={current}
                                            customerTitle={
                                                check.sources?.customer.title ??
                                                'Заказчик'
                                            }
                                            supplierTitle={
                                                check.sources?.supplier.title ??
                                                'Поставщик'
                                            }
                                            onSave={(value) => review(row.key, value)}
                                            questionHref={`/tenders/${tender.id}/questions?cost_key=${encodeURIComponent(row.key)}${teamId ? `&team_id=${teamId}` : ''}`}
                                            onTransfer={() => {
                                                setTransferKey(row.key);
                                                setNewExpense(false);
                                                setCategory('other_cost');
                                            }}
                                        />
                                    ))}
                            </section>
                            <GlassCard className="cost-gap__card">
                                <h2>Исходный текст и границы проверки</h2>
                                {check.sources
                                    ? (['customer', 'supplier'] as const).map(
                                          (side) => (
                                              <SourceView
                                                  key={side}
                                                  id={`cost-source-${side}`}
                                                  label={
                                                      side === 'customer'
                                                          ? 'Заказчик'
                                                          : 'Поставщик'
                                                  }
                                                  source={check.sources![side]}
                                              />
                                          ),
                                      )
                                    : null}
                                <p>
                                    Поиск охватывает типовые обязательства и явно
                                    заданные требования, но может пропустить
                                    формулировки. Сверьте результат с оригиналами.
                                    Автоматического обращения к площадке или отправки
                                    документов внешнему ИИ нет.
                                </p>
                            </GlassCard>
                        </>
                    ) : null}
                    {check.transfers.length > 0 ? (
                        <GlassCard className="cost-gap__card">
                            <h2>Перенесено в экономику</h2>
                            <p>
                                История сохраняется после повторных сравнений. Изменения
                                исходников не отменяют эти суммы. Корректировки делайте
                                в экономике заявки.
                            </p>
                            {check.transfers.map((t) => (
                                <div className="cost-gap__receipt" key={t.key}>
                                    <strong>
                                        {t.title} · +{gapMoney(t.amount)}
                                    </strong>
                                    <p>
                                        {gapCategories[t.category]}:{' '}
                                        {gapMoney(t.previous_amount)} →{' '}
                                        {gapMoney(t.result_amount)}
                                    </p>
                                </div>
                            ))}
                            <p>
                                Меняется плановая экономика и её версия согласования.
                                Фактические расходы и исходный план контроля исполнения
                                Ultra сохраняются отдельно.
                            </p>
                            <Link href={workUrl}>Открыть экономику заявки</Link>
                        </GlassCard>
                    ) : null}
                    <BottomSheet
                        open={replace}
                        onClose={() => {
                            if (!action.busy) setReplace(false);
                        }}
                        title="Повторить сравнение?"
                    >
                        <div className="cost-gap cost-gap__form">
                            <p>
                                Если источники или дополнительный перечень изменятся,
                                ручные решения будут сброшены. Уже перенесённые расходы
                                останутся в экономике и истории; повторно добавить тот
                                же пункт нельзя.
                            </p>
                            {feedback}
                            <Button
                                disabled={action.busy}
                                onClick={() => void analyze()}
                            >
                                Сравнить заново
                            </Button>
                        </div>
                    </BottomSheet>
                    <BottomSheet
                        open={transferring !== undefined}
                        onClose={() => {
                            if (!action.busy) setTransferKey(null);
                        }}
                        title="Добавить подтверждённый расход"
                    >
                        {transferring ? (
                            <div className="cost-gap cost-gap__form">
                                <h3>{transferring.title}</h3>
                                <p>
                                    Дополнительный расход:{' '}
                                    <strong>{gapMoney(decision?.amount)}</strong>
                                </p>
                                <label>
                                    Категория экономики
                                    <select
                                        value={category}
                                        disabled={action.busy}
                                        onChange={(e) => {
                                            setCategory(e.target.value as GapCategory);
                                            setNewExpense(false);
                                        }}
                                    >
                                        {Object.entries(gapCategories).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                                <p>
                                    Сейчас в категории:{' '}
                                    <strong>
                                        {gapMoney(check.economics[category])}
                                    </strong>
                                    . После добавления:{' '}
                                    <strong>
                                        {gapMoney(
                                            gapSum(
                                                check.economics[category],
                                                decision?.amount,
                                            ),
                                        )}
                                    </strong>
                                    .
                                </p>
                                {check.economics[category] === null ? (
                                    <InlineAlert title="Сумма пока неизвестна">
                                        Сначала задайте сумму категории в экономике
                                        заявки, включая явный ноль, если расходов пока
                                        нет. Затем обновите проверку.
                                    </InlineAlert>
                                ) : null}
                                <label className="cost-gap__check">
                                    <input
                                        type="checkbox"
                                        checked={newExpense}
                                        disabled={action.busy}
                                        onChange={(e) =>
                                            setNewExpense(e.target.checked)
                                        }
                                    />
                                    Это новый подтверждённый расход. Я проверил, что он
                                    ещё не учтён в других пунктах и экономике заявки.
                                </label>
                                {feedback}
                                <Button
                                    disabled={
                                        !current ||
                                        !newExpense ||
                                        check.economics[category] === null
                                    }
                                    onClick={() => void transfer()}
                                >
                                    Добавить в экономику
                                </Button>
                                <Link href={workUrl}>Перейти к экономике заявки</Link>
                            </div>
                        ) : null}
                    </BottomSheet>
                </div>
            </AppShell>
        </>
    );
}

function SourceInput({
    title,
    value,
    onChange,
    source,
    documents,
}: {
    title: string;
    value: SourceDraft;
    onChange: (value: SourceDraft) => void;
    source?: GapSource;
    documents: Props['documents'];
}) {
    return (
        <div className="cost-gap__source">
            <label>
                {title}
                <select
                    value={value.mode}
                    onChange={(e) =>
                        onChange({
                            ...value,
                            mode: e.target.value as SourceDraft['mode'],
                        })
                    }
                >
                    {source ? <option value="keep">Сохранённый источник</option> : null}
                    <option value="text">Вставить текст</option>
                    <option value="file">Загрузить файл</option>
                    {documents.length > 0 ? (
                        <option value="document">Документ этой заявки</option>
                    ) : null}
                </select>
            </label>
            {value.mode === 'keep' && source ? (
                <p>
                    {source.title} · {source.segments.length} фрагментов. Замена
                    документа требует нового выбора.
                </p>
            ) : null}
            {value.mode === 'text' ? (
                <label>
                    Текст: {title.toLowerCase()}
                    <textarea
                        rows={9}
                        maxLength={40000}
                        value={value.text}
                        onChange={(e) => onChange({ ...value, text: e.target.value })}
                    />
                </label>
            ) : null}
            {value.mode === 'file' ? (
                <label>
                    Файл: {title.toLowerCase()}
                    <input
                        type="file"
                        accept=".pdf,.docx,.txt"
                        onChange={(e) =>
                            onChange({ ...value, file: e.target.files?.[0] ?? null })
                        }
                    />
                </label>
            ) : null}
            {value.mode === 'document' ? (
                <label>
                    Версия документа
                    <select
                        value={value.document}
                        onChange={(e) =>
                            onChange({ ...value, document: e.target.value })
                        }
                    >
                        <option value="">Выберите документ</option>
                        {documents.map((d) => (
                            <option key={d.id} value={d.id}>
                                {d.title}
                            </option>
                        ))}
                    </select>
                </label>
            ) : null}
        </div>
    );
}

function RequirementCard({
    row,
    decision,
    transferred,
    canEdit,
    customerTitle,
    supplierTitle,
    onSave,
    onTransfer,
    questionHref,
}: {
    row: GapRow;
    decision?: GapDecision;
    transferred: boolean;
    canEdit: boolean;
    customerTitle: string;
    supplierTitle: string;
    onSave: (value: GapDecision) => Promise<GapDecision | null>;
    onTransfer: () => void;
    questionHref: string;
}) {
    const [status, setStatus] = useState(decision?.status ?? row.status);
    const [note, setNote] = useState(decision?.note ?? '');
    const [amount, setAmount] = useState(decision?.amount ?? '');
    const [reviewed, setReviewed] = useState(false);
    const dirty =
        status !== (decision?.status ?? row.status) ||
        note !== (decision?.note ?? '') ||
        amount !== (decision?.amount ?? '');
    const effective = decision?.status ?? row.status;
    async function save(event: FormEvent) {
        event.preventDefault();
        const saved = await onSave({
            status,
            note,
            amount: amount === '' ? null : amount,
        });
        if (saved) {
            setStatus(saved.status);
            setNote(saved.note);
            setAmount(saved.amount ?? '');
            setReviewed(false);
        }
    }
    return (
        <GlassCard className="cost-gap__card" as="article">
            <div className="cost-gap__row-title">
                <h3>{row.title}</h3>
                <Badge tone={effective === 'included' ? 'success' : 'warning'}>
                    {gapStatuses[effective]}
                </Badge>
            </div>
            <p>{row.reason}</p>
            <Link href={questionHref}>
                Подготовить вопрос заказчику по этому условию
            </Link>
            <div className="cost-gap__evidence">
                <Quotes
                    title={`Заказчик · ${customerTitle}`}
                    evidence={row.requirement_evidence}
                />
                <Quotes
                    title={`Предложение · ${supplierTitle}`}
                    evidence={row.offer_evidence}
                />
            </div>
            <p>
                Автоматически: {gapStatuses[row.status]}.{' '}
                {decision
                    ? 'Вывод проверен пользователем.'
                    : 'Пока не подтверждено пользователем.'}
            </p>
            {transferred ? (
                <Badge tone="success">
                    Расход уже перенесён · повторное добавление закрыто
                </Badge>
            ) : (
                <form className="cost-gap__form" onSubmit={(e) => void save(e)}>
                    <fieldset disabled={!canEdit} className="cost-gap__form">
                        <label>
                            Мой вывод
                            <select
                                value={status}
                                onChange={(e) => {
                                    setStatus(e.target.value as GapStatus);
                                    setReviewed(false);
                                }}
                            >
                                {Object.entries(gapStatuses).map(([key, label]) => (
                                    <option key={key} value={key}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Обоснование / что уточнить
                            <textarea
                                rows={2}
                                value={note}
                                maxLength={2000}
                                onChange={(e) => {
                                    setNote(e.target.value);
                                    setReviewed(false);
                                }}
                            />
                        </label>
                        <label>
                            Подтверждённый дополнительный расход, ₽
                            <input
                                inputMode="decimal"
                                value={amount}
                                maxLength={16}
                                placeholder="Неизвестно — оставьте пустым"
                                onChange={(e) => {
                                    setAmount(e.target.value);
                                    setReviewed(false);
                                }}
                            />
                        </label>
                        <label className="cost-gap__check">
                            <input
                                type="checkbox"
                                checked={reviewed}
                                onChange={(e) => setReviewed(e.target.checked)}
                            />
                            Я сверил цитаты и подтверждаю свой вывод и сумму
                        </label>
                        <div className="cost-gap__actions">
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={!reviewed}
                            >
                                Сохранить вывод
                            </Button>
                            <Button
                                disabled={
                                    !decision ||
                                    dirty ||
                                    decision.status === 'included' ||
                                    !decision.amount ||
                                    /^0+(?:[.,]0+)?$/.test(decision.amount)
                                }
                                onClick={onTransfer}
                            >
                                Перенести расход
                            </Button>
                        </div>
                        {dirty ? (
                            <small>
                                Есть несохранённые правки. Перед переносом сохраните
                                вывод.
                            </small>
                        ) : null}
                    </fieldset>
                </form>
            )}
        </GlassCard>
    );
}

function Quotes({ title, evidence }: { title: string; evidence: Evidence[] }) {
    return (
        <div>
            <h4>{title}</h4>
            {evidence.length === 0 ? (
                <p>Явное упоминание не найдено в прочитанном тексте.</p>
            ) : (
                evidence.map((piece, i) => (
                    <blockquote key={i}>
                        {piece.context ? (
                            <>
                                <small>{piece.context.ref}</small>
                                <p>{piece.context.text}</p>
                            </>
                        ) : null}
                        <p>{piece.text}</p>
                        <cite>{piece.ref}</cite>
                    </blockquote>
                ))
            )}
        </div>
    );
}
function SourceView({
    label,
    source,
    id,
}: {
    label: string;
    source: GapSource;
    id: string;
}) {
    return (
        <details
            className="cost-gap__source-text"
            id={id}
            open={window.location.hash === `#${id}`}
        >
            <summary>
                {label}: {source.title} ·{' '}
                {source.kind === 'text' ? 'текст пользователя' : 'извлечено из файла'}
            </summary>
            {source.warnings.map((w) => (
                <p className="cost-gap__warning" key={w}>
                    {w}
                </p>
            ))}
            <div tabIndex={0} aria-label={`Извлечённый текст: ${label}`}>
                {source.segments.map((s, i) => (
                    <p key={i}>
                        <small>{s.ref}</small>
                        <br />
                        {s.text}
                    </p>
                ))}
            </div>
        </details>
    );
}
