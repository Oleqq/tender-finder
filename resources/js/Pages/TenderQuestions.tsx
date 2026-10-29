import '../../css/tender-questions.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import {
    questionEvents,
    questionStatuses,
    useQuestionAction,
    type QuestionEvent,
    type QuestionResult,
    type QuestionSources,
    type TenderQuestion,
} from '../lib/tenderQuestions';
import type { PageProps } from '../types';
import { useTextDraft } from '../lib/useTextDraft';
import { TextDraftNotice } from '../Components/TextDraftNotice';

type Props = {
    tender: { id: number; title: string; fingerprint: string };
    questions: TenderQuestion[];
    sources: QuestionSources;
    topics: Record<string, string>;
    teamId: number | null;
    canEdit: boolean;
};
type Draft = {
    question_id: number | null;
    version: number;
    creation_key: string;
    title: string;
    topic: string;
    context: string;
    mode: 'manual' | 'card' | 'costgap';
    quote: string;
    source_title: string;
    source_ref: string;
    source_url: string;
    fragment: string;
    cost_key: string;
    evidence_index: string;
};

export default function TenderQuestionsPage() {
    const {
        tender,
        questions: initial,
        sources,
        topics,
        teamId,
        canEdit,
    } = usePage<PageProps<Props>>().props;
    const selected = sources.cost.find(
        (s) => s.key === new URLSearchParams(window.location.search).get('cost_key'),
    );
    const blank = (): Draft => ({
        question_id: null,
        version: 0,
        creation_key: crypto.randomUUID(),
        title: selected?.title ?? '',
        topic: selected ? 'costs' : 'general',
        context: '',
        mode: selected ? 'costgap' : sources.card.length ? 'card' : 'manual',
        quote: '',
        source_title: '',
        source_ref: '',
        source_url: '',
        fragment: '0',
        cost_key: selected?.key ?? sources.cost[0]?.key ?? '',
        evidence_index: '0',
    });
    const [questions, setQuestions] = useState(initial);
    const [composer, setComposer] = useState(
        canEdit && (initial.length === 0 || !!selected),
    );
    const [draft, setDraft] = useState(blank);
    const [confirmed, setConfirmed] = useState(false);
    const [filter, setFilter] = useState('active');
    const [target, setTarget] = useState<{
        question: TenderQuestion;
        action: 'sent' | 'archive';
    } | null>(null);
    const [sentReference, setSentReference] = useState('');
    const [sentConfirmed, setSentConfirmed] = useState(false);
    const [lastId, setLastId] = useState<number | null>(null);
    const action = useQuestionAction();
    const root = `/tenders/${tender.id}/questions`;
    const suffix = teamId ? `?team_id=${teamId}` : '';
    const work = `/tenders/${tender.id}/work${suffix}`;
    const check = `/tenders/${tender.id}/cost-check${suffix}`;
    const writable = canEdit && !action.busy;
    const cost = sources.cost.find((s) => s.key === draft.cost_key);
    const preview =
        draft.mode === 'card'
            ? draft.fragment === ''
                ? undefined
                : sources.card[Number(draft.fragment)]
            : draft.mode === 'costgap'
              ? cost?.evidence[Number(draft.evidence_index)]
              : null;
    function change(values: Partial<Draft>) {
        setDraft((old) => ({ ...old, ...values }));
        setConfirmed(false);
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
                            Перед обновлением страницы сохраните копию введённых правок.
                        </p>
                    ) : null}
                </div>
            ) : null}
            {action.message ? <p role="status">{action.message}</p> : null}
        </>
    );
    async function compose(event: FormEvent) {
        event.preventDefault();
        setLastId(null);
        const result = await action.run<QuestionResult>(
            'post',
            root + '/compose' + suffix,
            {
                ...draft,
                source_url: draft.source_url || null,
                fragment: draft.mode === 'card' ? Number(draft.fragment) : null,
                evidence_index:
                    draft.mode === 'costgap' ? Number(draft.evidence_index) : null,
                cost_source_hash: draft.mode === 'costgap' ? sources.cost_hash : null,
                tender_hash: tender.fingerprint,
                confirmed,
            },
            draft.question_id
                ? 'Основание обновлено, текст пересобран. Проверьте новую редакцию.'
                : 'Черновик сохранён. Проверьте его перед самостоятельной отправкой.',
        );
        if (result) {
            setQuestions(result.questions);
            setComposer(false);
            setConfirmed(false);
            setDraft(blank());
            setFilter('active');
        }
    }
    async function mutate(
        id: number,
        path: string,
        method: 'post' | 'patch',
        data: unknown,
        success: string,
    ) {
        setLastId(id);
        const result = await action.run<QuestionResult>(
            method,
            `${root}/${id}${path}${suffix}`,
            data,
            success,
        );
        if (result) setQuestions(result.questions);
        return result?.questions.find((q) => q.id === id) ?? null;
    }
    function recompose(question: TenderQuestion) {
        const fragmentIndex = sources.card.findIndex(
            (s) => s.text === question.source.text && s.ref === question.source.ref,
        );
        setDraft({
            ...blank(),
            question_id: question.id,
            version: question.version,
            title: question.title,
            topic: question.topic,
            context: question.context ?? '',
            mode:
                question.source.kind === 'costgap' &&
                sources.cost.some((s) => s.key === question.source.cost_key)
                    ? 'costgap'
                    : question.source.kind === 'card' && sources.card.length
                      ? 'card'
                      : 'manual',
            quote: question.source.text,
            source_title: question.source.title,
            source_ref: question.source.ref,
            source_url: question.source.url ?? '',
            fragment: fragmentIndex >= 0 ? String(fragmentIndex) : '',
            cost_key: question.source.cost_key ?? sources.cost[0]?.key ?? '',
            evidence_index: String(question.source.evidence_index ?? 0),
        });
        setConfirmed(false);
        setComposer(true);
        setLastId(null);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    const visible = questions.filter((q) =>
        filter === 'archived'
            ? !!q.archived_at
            : !q.archived_at && (filter === 'active' || q.status === filter),
    );
    return (
        <>
            <Head title="Вопросы заказчику" />
            <AppShell
                title="Вопросы заказчику"
                eyebrow="Pro / Ultra · PRO-03"
                backHref={work}
            >
                <div className="tender-questions">
                    <header className="tq-intro">
                        <h2>{tender.title}</h2>
                        <p>
                            Соберите вопрос по точной цитате, отправьте его заказчику
                            самостоятельно и сохраните полученный ответ рядом с заявкой.
                        </p>
                        <div className="tq-actions">
                            <Link href={work}>Экономика и участие</Link>
                            <Link href={check}>Неучтённые расходы PRO-02</Link>
                            {canEdit && !composer ? (
                                <Button
                                    onClick={() => {
                                        setDraft(blank());
                                        setConfirmed(false);
                                        setComposer(true);
                                        setLastId(null);
                                    }}
                                >
                                    Новый вопрос
                                </Button>
                            ) : null}
                        </div>
                    </header>
                    {!canEdit ? (
                        <InlineAlert title="Только просмотр">
                            Изменения доступны редакторам действующей команды с Pro или
                            Ultra.
                        </InlineAlert>
                    ) : null}
                    {composer && canEdit ? (
                        <GlassCard className="tq-card">
                            <h2>
                                {draft.question_id
                                    ? 'Обновить основание и пересобрать'
                                    : 'Новый вопрос'}
                            </h2>
                            {draft.question_id ? (
                                <InlineAlert
                                    title="Ручные правки будут заменены"
                                    tone="warning"
                                >
                                    После подтверждения текст этого черновика будет
                                    собран заново из выбранной цитаты. Прежняя редакция
                                    останется в истории.
                                </InlineAlert>
                            ) : null}
                            <form className="tq-form" onSubmit={(e) => void compose(e)}>
                                <fieldset className="tq-form" disabled={!writable}>
                                    <label htmlFor="question-title">
                                        Название вопроса
                                    </label>
                                    <input
                                        id="question-title"
                                        required
                                        value={draft.title}
                                        maxLength={160}
                                        onChange={(e) =>
                                            change({ title: e.target.value })
                                        }
                                        placeholder="Например: монтаж и обучение персонала"
                                    />
                                    <label htmlFor="question-topic">Что уточняем</label>
                                    <select
                                        id="question-topic"
                                        value={draft.topic}
                                        onChange={(e) =>
                                            change({ topic: e.target.value })
                                        }
                                    >
                                        {Object.entries(topics).map(([key, name]) => (
                                            <option value={key} key={key}>
                                                {name}
                                            </option>
                                        ))}
                                    </select>
                                    <label htmlFor="question-mode">
                                        Основание вопроса
                                    </label>
                                    <select
                                        id="question-mode"
                                        value={draft.mode}
                                        onChange={(e) =>
                                            change({
                                                mode: e.target.value as Draft['mode'],
                                            })
                                        }
                                    >
                                        <option value="manual">
                                            Вставить цитату вручную
                                        </option>
                                        {sources.card.length ? (
                                            <option value="card">
                                                Фрагмент описания карточки
                                            </option>
                                        ) : null}
                                        {sources.cost.length ? (
                                            <option value="costgap">
                                                Требование из PRO-02
                                            </option>
                                        ) : null}
                                    </select>
                                    {draft.mode === 'manual' ? (
                                        <>
                                            <label htmlFor="question-source-title">
                                                Название документа / источника
                                            </label>
                                            <input
                                                id="question-source-title"
                                                required
                                                value={draft.source_title}
                                                maxLength={200}
                                                onChange={(e) =>
                                                    change({
                                                        source_title: e.target.value,
                                                    })
                                                }
                                                placeholder="Техническое задание от 29.10"
                                            />
                                            <label htmlFor="question-source-ref">
                                                Где находится условие
                                            </label>
                                            <input
                                                id="question-source-ref"
                                                required
                                                value={draft.source_ref}
                                                maxLength={160}
                                                onChange={(e) =>
                                                    change({
                                                        source_ref: e.target.value,
                                                    })
                                                }
                                                placeholder="Страница 4, пункт 2.3"
                                            />
                                            <label htmlFor="question-source-url">
                                                Ссылка на источник, если есть
                                            </label>
                                            <input
                                                id="question-source-url"
                                                type="url"
                                                value={draft.source_url}
                                                maxLength={2000}
                                                onChange={(e) =>
                                                    change({
                                                        source_url: e.target.value,
                                                    })
                                                }
                                                placeholder="https://…"
                                            />
                                            <label htmlFor="question-quote">
                                                Точная цитата условия
                                            </label>
                                            <textarea
                                                id="question-quote"
                                                rows={5}
                                                required
                                                value={draft.quote}
                                                maxLength={4000}
                                                onChange={(e) =>
                                                    change({ quote: e.target.value })
                                                }
                                            />
                                            <p>
                                                Цитату и ссылку вводите вы. Сверьте их с
                                                оригиналом; источник автоматически не
                                                проверяется.
                                            </p>
                                        </>
                                    ) : draft.mode === 'card' ? (
                                        <>
                                            <label htmlFor="question-fragment">
                                                Фрагмент описания
                                            </label>
                                            <select
                                                id="question-fragment"
                                                required
                                                value={draft.fragment}
                                                onChange={(e) =>
                                                    change({ fragment: e.target.value })
                                                }
                                            >
                                                <option value="">
                                                    Выберите актуальный фрагмент
                                                </option>
                                                {sources.card.map((s, i) => (
                                                    <option key={i} value={i}>
                                                        {s.ref} · {s.text.slice(0, 90)}
                                                    </option>
                                                ))}
                                            </select>
                                        </>
                                    ) : (
                                        <>
                                            <label htmlFor="question-cost-key">
                                                Требование PRO-02
                                            </label>
                                            <select
                                                id="question-cost-key"
                                                value={draft.cost_key}
                                                onChange={(e) => {
                                                    const row = sources.cost.find(
                                                        (s) => s.key === e.target.value,
                                                    );
                                                    change({
                                                        cost_key: e.target.value,
                                                        evidence_index: '0',
                                                        title:
                                                            row?.title ?? draft.title,
                                                    });
                                                }}
                                            >
                                                {sources.cost.map((s) => (
                                                    <option key={s.key} value={s.key}>
                                                        {s.title}
                                                    </option>
                                                ))}
                                            </select>
                                            <label htmlFor="question-evidence">
                                                Цитата заказчика
                                            </label>
                                            <select
                                                id="question-evidence"
                                                value={draft.evidence_index}
                                                onChange={(e) =>
                                                    change({
                                                        evidence_index: e.target.value,
                                                    })
                                                }
                                            >
                                                {cost?.evidence.map((s, i) => (
                                                    <option key={i} value={i}>
                                                        {s.ref} · {s.text.slice(0, 90)}
                                                    </option>
                                                ))}
                                            </select>
                                            {sources.cost_stale.length ? (
                                                <InlineAlert
                                                    title="PRO-02 требует обновления"
                                                    tone="warning"
                                                >
                                                    {sources.cost_stale.join(' ')}{' '}
                                                    Обновите сравнение и затем эту
                                                    страницу.
                                                </InlineAlert>
                                            ) : null}
                                            <p>
                                                В черновик войдёт цитата требования.
                                                Содержание предложения поставщика
                                                остаётся внутри проверки PRO-02.
                                            </p>
                                        </>
                                    )}
                                    {preview ? (
                                        <blockquote>
                                            <p>{preview.text}</p>
                                            <cite>
                                                {draft.mode === 'costgap'
                                                    ? preview.ref.startsWith(
                                                          'Ваш перечень',
                                                      )
                                                        ? 'Ваш перечень PRO-02'
                                                        : cost?.source_title
                                                    : 'Карточка закупки'}{' '}
                                                · {preview.ref}
                                            </cite>
                                        </blockquote>
                                    ) : null}
                                    <label htmlFor="question-context">
                                        В чём неопределённость / что нужно уточнить
                                    </label>
                                    <textarea
                                        id="question-context"
                                        value={draft.context}
                                        maxLength={2000}
                                        rows={3}
                                        onChange={(e) =>
                                            change({ context: e.target.value })
                                        }
                                        placeholder="Например: не указан объём обучения и кто оплачивает выезд специалиста"
                                    />
                                    <label className="tq-check">
                                        <input
                                            type="checkbox"
                                            checked={confirmed}
                                            onChange={(e) =>
                                                setConfirmed(e.target.checked)
                                            }
                                        />
                                        Я сверил цитату с актуальным источником и
                                        проверил пояснение
                                    </label>
                                    <div className="tq-actions">
                                        <Button
                                            type="submit"
                                            disabled={
                                                !confirmed ||
                                                (draft.mode !== 'manual' && !preview) ||
                                                (draft.mode === 'costgap' &&
                                                    sources.cost_stale.length > 0)
                                            }
                                        >
                                            Собрать и сохранить черновик
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            onClick={() => setComposer(false)}
                                        >
                                            Отмена
                                        </Button>
                                    </div>
                                </fieldset>
                            </form>
                        </GlassCard>
                    ) : null}
                    {lastId === null && !target ? feedback : null}
                    <section className="tq-list" aria-label="Сохранённые вопросы">
                        <h2>Вопросы и ответы</h2>
                        <div className="tq-filters">
                            {[
                                ['active', 'Все действующие'],
                                ['draft', 'Черновики'],
                                ['sent', 'Отправленные'],
                                ['answered', 'С ответом'],
                                ['archived', 'Архив'],
                            ].map(([key, label]) => (
                                <button
                                    type="button"
                                    key={key}
                                    aria-pressed={filter === key}
                                    onClick={() => setFilter(key)}
                                >
                                    {label}{' '}
                                    <strong>
                                        {
                                            questions.filter((q) =>
                                                key === 'archived'
                                                    ? !!q.archived_at
                                                    : !q.archived_at &&
                                                      (key === 'active' ||
                                                          q.status === key),
                                            ).length
                                        }
                                    </strong>
                                </button>
                            ))}
                        </div>
                        {visible.length === 0 ? (
                            <InlineAlert title="Здесь пока нет вопросов">
                                Создайте вопрос из условия закупки или откройте другой
                                фильтр.
                            </InlineAlert>
                        ) : null}
                        {visible.map((q) => (
                            <QuestionCard
                                key={`${q.id}:${q.source_revision}`}
                                question={q}
                                canEdit={canEdit}
                                busy={action.busy}
                                root={root}
                                suffix={suffix}
                                feedback={!target && lastId === q.id ? feedback : null}
                                onSave={(data) =>
                                    mutate(q.id, '', 'patch', data, 'Правки сохранены.')
                                }
                                onAnswer={(data) =>
                                    mutate(
                                        q.id,
                                        '/answer',
                                        'patch',
                                        data,
                                        'Ответ сохранён как введённые пользователем данные.',
                                    )
                                }
                                onRecompose={() => recompose(q)}
                                onSent={() => {
                                    setTarget({ question: q, action: 'sent' });
                                    setSentReference('');
                                    setSentConfirmed(false);
                                    setLastId(q.id);
                                }}
                                onArchive={() => {
                                    setTarget({ question: q, action: 'archive' });
                                    setLastId(q.id);
                                }}
                                onCopy={async () => {
                                    setLastId(q.id);
                                    return action.run<{ text: string }>(
                                        'get',
                                        `${root}/${q.id}/export?version=${q.version}&copy=1${teamId ? `&team_id=${teamId}` : ''}`,
                                        null,
                                    );
                                }}
                                onHistory={async () => {
                                    setLastId(q.id);
                                    return action.run<{ events: QuestionEvent[] }>(
                                        'get',
                                        `${root}/${q.id}/history${suffix}`,
                                        null,
                                    );
                                }}
                            />
                        ))}
                    </section>
                    {sources.card.length ? (
                        <GlassCard className="tq-card">
                            <details id="question-card-source">
                                <summary>
                                    Описание карточки — источник фрагментов
                                </summary>
                                <div className="tq-source-scroll">
                                    {sources.card.map((s, i) => (
                                        <p key={i}>
                                            <small>{s.ref}</small>
                                            <br />
                                            {s.text}
                                        </p>
                                    ))}
                                </div>
                            </details>
                        </GlassCard>
                    ) : null}
                    <BottomSheet
                        open={target !== null}
                        title={
                            target?.action === 'sent'
                                ? 'Отметить самостоятельную отправку'
                                : target?.question.archived_at
                                  ? 'Восстановить вопрос?'
                                  : 'Перенести вопрос в архив?'
                        }
                        onClose={() => {
                            if (!action.busy) setTarget(null);
                        }}
                    >
                        {target ? (
                            <div className="tender-questions tq-form">
                                <h3>{target.question.title}</h3>
                                {target.action === 'sent' ? (
                                    <>
                                        <p>
                                            Проверьте, что заказчику передана именно эта
                                            сохранённая редакция. Отметка не
                                            подтверждает подачу на площадке.
                                        </p>
                                        <textarea
                                            readOnly
                                            rows={7}
                                            aria-label="Текст для отметки отправки"
                                            value={target.question.body}
                                        />
                                        <label htmlFor="question-sent-ref">
                                            Канал / номер обращения, если есть
                                        </label>
                                        <input
                                            id="question-sent-ref"
                                            maxLength={500}
                                            value={sentReference}
                                            disabled={action.busy}
                                            onChange={(e) =>
                                                setSentReference(e.target.value)
                                            }
                                            placeholder="ЭТП, обращение №…"
                                        />
                                        <label className="tq-check">
                                            <input
                                                type="checkbox"
                                                checked={sentConfirmed}
                                                disabled={action.busy}
                                                onChange={(e) =>
                                                    setSentConfirmed(e.target.checked)
                                                }
                                            />
                                            Я самостоятельно отправил заказчику именно
                                            этот текст
                                        </label>
                                    </>
                                ) : (
                                    <p>
                                        {target.question.archived_at
                                            ? 'Вопрос снова появится среди действующих. Текст и история сохранятся.'
                                            : 'Сохранённые вопрос и ответ останутся в архиве; вопрос можно восстановить. Несохранённые правки в форму архива не попадут.'}
                                    </p>
                                )}
                                {feedback}
                                <Button
                                    disabled={
                                        !writable ||
                                        (target.action === 'sent' && !sentConfirmed)
                                    }
                                    onClick={() => {
                                        const item = target;
                                        void mutate(
                                            item.question.id,
                                            item.action === 'sent'
                                                ? '/sent'
                                                : '/archive',
                                            item.action === 'sent' ? 'post' : 'patch',
                                            {
                                                version: item.question.version,
                                                confirmed: true,
                                                ...(item.action === 'sent'
                                                    ? { sent_reference: sentReference }
                                                    : {
                                                          archived:
                                                              !item.question
                                                                  .archived_at,
                                                      }),
                                            },
                                            item.action === 'sent'
                                                ? 'Отправка отмечена вручную. Текст зафиксирован.'
                                                : 'Состояние архива сохранено.',
                                        ).then((saved) => {
                                            if (saved) setTarget(null);
                                        });
                                    }}
                                >
                                    {target.action === 'sent'
                                        ? 'Отметить отправленным'
                                        : target.question.archived_at
                                          ? 'Восстановить'
                                          : 'В архив'}
                                </Button>
                            </div>
                        ) : null}
                    </BottomSheet>
                </div>
            </AppShell>
        </>
    );
}

function QuestionCard({
    question: q,
    canEdit,
    busy,
    root,
    suffix,
    feedback,
    onSave,
    onAnswer,
    onRecompose,
    onSent,
    onArchive,
    onCopy,
    onHistory,
}: {
    question: TenderQuestion;
    canEdit: boolean;
    busy: boolean;
    root: string;
    suffix: string;
    feedback: ReactNode;
    onSave: (data: unknown) => Promise<TenderQuestion | null>;
    onAnswer: (data: unknown) => Promise<TenderQuestion | null>;
    onRecompose: () => void;
    onSent: () => void;
    onArchive: () => void;
    onCopy: () => Promise<{ text: string } | null>;
    onHistory: () => Promise<{ events: QuestionEvent[] } | null>;
}) {
    const textDraft = useTextDraft(
        `${root}${suffix}:question:${q.id}`,
        { title: q.title, body: q.body },
        canEdit && !q.marked_sent_at && !q.archived_at,
        String(q.source_revision),
    );
    const { title, body } = textDraft.fields;
    const [reviewed, setReviewed] = useState(false);
    const answerDraft = useTextDraft(
        `${root}${suffix}:answer:${q.id}`,
        {
            answer: q.answer ?? '',
            answerSource: q.answer_source ?? '',
            received: q.answer_received_on ?? '',
        },
        canEdit && !!q.marked_sent_at && !q.archived_at,
        String(q.source_revision),
    );
    const { answer, answerSource, received } = answerDraft.fields;
    const [answerConfirmed, setAnswerConfirmed] = useState(false);
    const [copied, setCopied] = useState('');
    const [history, setHistory] = useState<QuestionEvent[] | null>(null);
    const dirty = textDraft.dirty;
    const editable = canEdit && !busy && !q.archived_at;
    const copyable =
        !busy &&
        !dirty &&
        !textDraft.pending &&
        (!!q.marked_sent_at || (!q.archived_at && q.stale.length === 0));
    async function save(event: FormEvent) {
        event.preventDefault();
        const submitted = textDraft.fields;
        const saved = await onSave({
            version: q.version,
            confirmed: reviewed,
            title,
            body,
        });
        if (saved) {
            textDraft.acceptSaved({ body: saved.body, title: saved.title }, submitted);
            setReviewed(false);
            setCopied('');
            setHistory(null);
        }
    }
    async function saveAnswer(event: FormEvent) {
        event.preventDefault();
        const submitted = answerDraft.fields;
        const saved = await onAnswer({
            version: q.version,
            confirmed: answerConfirmed,
            answer,
            answer_source: answerSource,
            answer_received_on: received || null,
        });
        if (saved) {
            answerDraft.acceptSaved(
                {
                    answer: saved.answer ?? '',
                    answerSource: saved.answer_source ?? '',
                    received: saved.answer_received_on ?? '',
                },
                submitted,
            );
            setAnswerConfirmed(false);
            setHistory(null);
        }
    }
    async function copy() {
        const result = await onCopy();
        if (!result) return;
        try {
            await navigator.clipboard.writeText(result.text);
            setCopied(
                'Текст скопирован. Перед отправкой проверьте требования площадки.',
            );
        } catch {
            setCopied(
                'Автокопирование недоступно. Выделите сохранённый текст и скопируйте вручную или скачайте TXT.',
            );
        }
    }
    return (
        <GlassCard as="article" className="tq-card">
            <div id={`question-${q.id}`} className="tq-heading">
                <h3>{q.title}</h3>
                <Badge
                    tone={
                        q.status === 'answered'
                            ? 'success'
                            : q.status === 'sent'
                              ? 'accent'
                              : 'neutral'
                    }
                >
                    {q.archived_at ? 'Архив' : questionStatuses[q.status]}
                </Badge>
            </div>
            <blockquote>
                <p>{q.source.text}</p>
                <cite>
                    {q.source.title} · {q.source.ref}
                    {q.source.user_supplied ? ' · пользовательский текст' : ''}
                </cite>
                {q.source_href ? (
                    q.source.kind === 'card' ? (
                        <a
                            href="#question-card-source"
                            onClick={() => {
                                const source =
                                    document.querySelector<HTMLDetailsElement>(
                                        '#question-card-source',
                                    );
                                if (source) source.open = true;
                            }}
                        >
                            Открыть источник
                        </a>
                    ) : (
                        <a
                            href={q.source_href}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {q.source.kind === 'manual'
                                ? 'Открыть указанную ссылку'
                                : 'Открыть источник PRO-02'}
                        </a>
                    )
                ) : null}
            </blockquote>
            {q.stale.length ? (
                <InlineAlert title="Основание изменилось" tone="warning">
                    {q.stale.join(' ')}{' '}
                    {q.marked_sent_at
                        ? 'Здесь сохранена прежняя отправленная редакция; ответ можно внести к ней.'
                        : 'Обновите основание перед копированием и отметкой отправки.'}
                </InlineAlert>
            ) : null}
            {q.marked_sent_at || q.archived_at ? (
                <details open>
                    <summary>Сохранённый текст вопроса</summary>
                    <p className="tq-text">{q.body}</p>
                </details>
            ) : (
                <form className="tq-form" onSubmit={(e) => void save(e)}>
                    <TextDraftNotice
                        draft={textDraft}
                        disabled={busy}
                        onResolve={() => {
                            setReviewed(false);
                            setCopied('');
                        }}
                    />
                    <fieldset
                        className="tq-form"
                        disabled={!editable || !!textDraft.pending}
                    >
                        <label htmlFor={`tq-title-${q.id}`}>Название</label>
                        <input
                            id={`tq-title-${q.id}`}
                            value={title}
                            maxLength={160}
                            required
                            onChange={(e) => {
                                textDraft.change({ title: e.target.value });
                                setReviewed(false);
                            }}
                        />
                        <label htmlFor={`tq-body-${q.id}`}>Текст вопроса</label>
                        <textarea
                            id={`tq-body-${q.id}`}
                            rows={9}
                            value={body}
                            maxLength={12000}
                            required
                            onChange={(e) => {
                                textDraft.change({ body: e.target.value });
                                setReviewed(false);
                                setCopied('');
                            }}
                        />
                        <label className="tq-check">
                            <input
                                type="checkbox"
                                checked={reviewed}
                                onChange={(e) => setReviewed(e.target.checked)}
                            />
                            Я проверил текст перед сохранением
                        </label>
                        <Button type="submit" variant="secondary" disabled={!reviewed}>
                            Сохранить правки
                        </Button>
                    </fieldset>
                </form>
            )}
            <div className="tq-actions">
                <Button
                    variant="secondary"
                    disabled={!copyable}
                    onClick={() => void copy()}
                >
                    Скопировать текст
                </Button>
                {copyable ? (
                    <a
                        className="button button--secondary button--md"
                        href={`${root}/${q.id}/export?version=${q.version}${suffix ? '&' + suffix.slice(1) : ''}`}
                    >
                        Скачать TXT
                    </a>
                ) : null}
                {!q.marked_sent_at && !q.archived_at ? (
                    <>
                        <Button
                            disabled={!editable || dirty || q.stale.length > 0}
                            onClick={onSent}
                        >
                            Отметить отправленным
                        </Button>
                        <Button
                            variant="ghost"
                            disabled={!editable}
                            onClick={onRecompose}
                        >
                            Обновить основание
                        </Button>
                    </>
                ) : null}
                <Button variant="ghost" disabled={!canEdit || busy} onClick={onArchive}>
                    {q.archived_at ? 'Восстановить' : 'В архив'}
                </Button>
            </div>
            {dirty ? (
                <p role="status">
                    Есть несохранённые правки. Перед копированием и отметкой отправки
                    сохраните текст.
                </p>
            ) : null}
            {copied ? <p role="status">{copied}</p> : null}
            {q.marked_sent_at ? (
                <p>
                    Пользователь отметил отправку:{' '}
                    {new Date(q.marked_sent_at).toLocaleString('ru-RU')}.{' '}
                    {q.sent_reference ? `Канал / обращение: ${q.sent_reference}.` : ''}{' '}
                    Это ручная отметка, не подтверждение площадки.
                </p>
            ) : null}
            {q.answer ? (
                <div className="tq-answer">
                    <h4>Сохранённый ответ</h4>
                    <p className="tq-text">{q.answer}</p>
                    <p>
                        Источник: {q.answer_source}
                        {q.answer_received_on
                            ? ` · дата получения: ${q.answer_received_on}`
                            : ''}
                        . Ответ внесён пользователем; проверьте его перед изменением
                        расчёта.
                    </p>
                </div>
            ) : null}
            {q.marked_sent_at && !q.archived_at && canEdit ? (
                <details>
                    <summary>
                        {q.answer
                            ? 'Уточнить сохранённый ответ'
                            : 'Добавить полученный ответ'}
                    </summary>
                    <form className="tq-form" onSubmit={(e) => void saveAnswer(e)}>
                        <TextDraftNotice
                            draft={answerDraft}
                            disabled={busy}
                            saveLabel="Сохранить ответ"
                            onResolve={() => setAnswerConfirmed(false)}
                        />
                        <fieldset
                            className="tq-form"
                            disabled={busy || !!answerDraft.pending}
                        >
                            <label htmlFor={`tq-answer-${q.id}`}>Ответ заказчика</label>
                            <textarea
                                id={`tq-answer-${q.id}`}
                                rows={5}
                                required
                                maxLength={10000}
                                value={answer}
                                onChange={(e) => {
                                    answerDraft.change({ answer: e.target.value });
                                    setAnswerConfirmed(false);
                                }}
                            />
                            <label htmlFor={`tq-answer-source-${q.id}`}>
                                Откуда получен ответ
                            </label>
                            <input
                                id={`tq-answer-source-${q.id}`}
                                required
                                maxLength={500}
                                value={answerSource}
                                onChange={(e) => {
                                    answerDraft.change({
                                        answerSource: e.target.value,
                                    });
                                    setAnswerConfirmed(false);
                                }}
                                placeholder="ЭТП, разъяснение №… от…"
                            />
                            <label htmlFor={`tq-received-${q.id}`}>
                                Дата получения, если известна
                            </label>
                            <input
                                id={`tq-received-${q.id}`}
                                type="date"
                                value={received}
                                onChange={(e) => {
                                    answerDraft.change({ received: e.target.value });
                                    setAnswerConfirmed(false);
                                }}
                            />
                            <label className="tq-check">
                                <input
                                    type="checkbox"
                                    checked={answerConfirmed}
                                    onChange={(e) =>
                                        setAnswerConfirmed(e.target.checked)
                                    }
                                />
                                Я сверил ответ с указанным источником
                            </label>
                            <Button type="submit" disabled={!answerConfirmed}>
                                Сохранить ответ
                            </Button>
                        </fieldset>
                    </form>
                </details>
            ) : null}
            {feedback}
            <details>
                <summary>История изменений</summary>
                <div className="tq-form">
                    <p>
                        Последние 20 редакций. Отправленный текст и прежние ответы
                        сохраняются.
                    </p>
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() => {
                            void onHistory().then((result) => {
                                if (result) setHistory(result.events);
                            });
                        }}
                    >
                        Загрузить историю
                    </Button>
                    {history?.map((event) => (
                        <details key={event.version}>
                            <summary>
                                v{event.version} ·{' '}
                                {questionEvents[event.action] ?? event.action}
                            </summary>
                            <p className="tq-text">{event.snapshot.body}</p>
                            {event.snapshot.answer ? (
                                <>
                                    <h4>Ответ этой редакции</h4>
                                    <p className="tq-text">{event.snapshot.answer}</p>
                                    <p>{event.snapshot.answer_source}</p>
                                </>
                            ) : null}
                            <cite>
                                {event.snapshot.source.title} ·{' '}
                                {event.snapshot.source.ref}
                            </cite>
                        </details>
                    ))}
                </div>
            </details>
        </GlassCard>
    );
}
