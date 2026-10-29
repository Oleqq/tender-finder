import '../../css/proposals.css';
import '../../css/winner-opportunities.css';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import { WinnerEvidenceForm } from '../Components/WinnerEvidenceForm';
import { useProposalAction } from '../lib/proposals';
import {
    winnerOutcomes,
    type WinnerItem,
    type WinnerOpportunity as Opportunity,
    type WinnerPage,
} from '../lib/winnerOpportunities';
import type { PageProps } from '../types';
import { useTextDraft } from '../lib/useTextDraft';
import { TextDraftNotice } from '../Components/TextDraftNotice';

type Event = {
    id: number;
    action: string;
    created_at: string;
    snapshot: {
        body?: string | null;
        note?: string | null;
        outcome?: string;
        evidence?: Opportunity['evidence'];
        draft_source?: Opportunity['draft_source'];
    };
};
type Props = {
    opportunity: Opportunity;
    catalog: { version: number; items: WinnerItem[] };
    candidates: { item: WinnerItem; terms: string[] }[];
    events: WinnerPage<Event>;
};
export default function WinnerOpportunity() {
    const {
        opportunity: initial,
        catalog,
        candidates,
        events,
    } = usePage<PageProps<Props>>().props;
    const [row, setRow] = useState(initial);
    const textDraft = useTextDraft(
        `winner:${initial.id}`,
        { body: row.body ?? '' },
        !row.archived,
        String(row.version),
    );
    const { body } = textDraft.fields;
    const [selected, setSelected] = useState(
        initial.draft_source?.items.map((item) => item.id) ?? [],
    );
    const [company, setCompany] = useState(initial.draft_source?.company ?? '');
    const [contacts, setContacts] = useState(initial.draft_source?.contacts ?? '');
    const [rationale, setRationale] = useState(initial.draft_source?.rationale ?? '');
    const [confirmed, setConfirmed] = useState(false);
    const [reviewed, setReviewed] = useState(false);
    const [replace, setReplace] = useState(false);
    const [editing, setEditing] = useState(false);
    const [decision, setDecision] = useState<'archive' | 'outcome' | null>(null);
    const [outcome, setOutcome] = useState('contacted');
    const [note, setNote] = useState('');
    const [decisionConfirmed, setDecisionConfirmed] = useState(false);
    const [copyMessage, setCopyMessage] = useState('');
    const [copyBusy, setCopyBusy] = useState(false);
    const action = useProposalAction();
    const root = `/winner-opportunities/${row.id}`;
    const dirty = textDraft.dirty;
    const canExport =
        !!row.body &&
        !row.stale &&
        !dirty &&
        !textDraft.pending &&
        !action.busy &&
        !copyBusy;
    async function compose() {
        const submitted = textDraft.fields;
        const result = await action.run<{ opportunity: Opportunity }>(
            'post',
            root + '/compose',
            {
                version: row.version,
                catalog_version: catalog.version,
                item_ids: selected,
                company,
                contacts,
                rationale,
                confirmed,
            },
            'Черновик собран и сохранён. Проверьте перед отправкой.',
        );
        if (result) {
            setRow(result.opportunity);
            textDraft.acceptSaved({ body: result.opportunity.body ?? '' }, submitted);
            setConfirmed(false);
            setReviewed(false);
            setReplace(false);
            setCopyMessage('');
        }
    }
    async function saveDraft() {
        const submitted = textDraft.fields;
        const result = await action.run<{ opportunity: Opportunity }>(
            'patch',
            root + '/draft',
            { version: row.version, body, confirmed: reviewed },
            'Правки сохранены.',
        );
        if (result) {
            setRow(result.opportunity);
            textDraft.acceptSaved({ body: result.opportunity.body ?? '' }, submitted);
            setReviewed(false);
            setCopyMessage('');
        }
    }
    async function decide() {
        const archive = decision === 'archive';
        const result = await action.run<{ opportunity: Opportunity }>(
            archive ? 'patch' : 'post',
            root + (archive ? '/archive' : '/outcome'),
            {
                version: row.version,
                confirmed: decisionConfirmed,
                ...(archive ? { archived: !row.archived } : { outcome, note }),
            },
            archive ? 'Архив обновлён.' : 'Результат записан.',
        );
        if (result) {
            setRow(result.opportunity);
            setDecision(null);
            router.reload({ only: ['events'] });
        }
    }
    async function copy() {
        setCopyBusy(true);
        try {
            const saved = await axios.get<string>(
                root + '/export?version=' + row.version,
                { responseType: 'text' },
            );
            await navigator.clipboard.writeText(saved.data.replace(/^\uFEFF/, ''));
            setCopyMessage(
                'Сохранённый текст скопирован. Отправьте его самостоятельно.',
            );
        } catch {
            setCopyMessage(
                'Не удалось скопировать актуальный текст. Проверьте доступ и обновите страницу после сохранения своих правок.',
            );
        } finally {
            setCopyBusy(false);
        }
    }
    function openDecision(type: 'archive' | 'outcome') {
        setDecision(type);
        setDecisionConfirmed(false);
        setNote('');
    }
    return (
        <>
            <Head title="Предложение победителю" />
            <AppShell
                className="winner-page"
                title="Предложение победителю"
                eyebrow="Личное · Ultra"
                backHref="/winner-opportunities"
            >
                <section className="proposal-intro">
                    <Badge>
                        {row.archived ? 'В архиве' : winnerOutcomes[row.outcome]}
                    </Badge>
                    <h2>{row.evidence.title}</h2>
                    <p>Победитель по вашей проверке: {row.evidence.winner_name}</p>
                    <p>
                        Наличие закупки не подтверждает потребность в субподряде.
                        Обращения вы отправляете самостоятельно.
                    </p>
                </section>
                {action.error ? (
                    <InlineAlert tone="danger" title="Изменения не сохранены">
                        {action.error}{' '}
                        <Link href={root} preserveState={false}>
                            Обновить страницу
                        </Link>
                    </InlineAlert>
                ) : null}
                {action.message ? (
                    <div role="status">
                        <InlineAlert title="Готово">{action.message}</InlineAlert>
                    </div>
                ) : null}
                <GlassCard className="proposal-card">
                    <h2>Основание: итоги закупки</h2>
                    <p>
                        Дата итогов: {row.evidence.result_on} · проверено вами:{' '}
                        {row.evidence.checked_on}
                    </p>
                    <a href={row.evidence.source_url} target="_blank" rel="noreferrer">
                        Открыть источник итогов
                    </a>
                    <p>{row.evidence.source_reference}</p>
                    <blockquote className="winner-source">
                        {row.evidence.source_quote}
                    </blockquote>
                    <p>Интересующая часть закупки — ваше описание:</p>
                    <p className="proposal-text">{row.evidence.scope}</p>
                    <Button
                        variant="secondary"
                        disabled={action.busy || row.archived || dirty}
                        onClick={() => setEditing(true)}
                    >
                        Изменить основание
                    </Button>
                </GlassCard>
                {row.archived ? (
                    <InlineAlert title="Запись в архиве">
                        История сохранена. Для изменения верните запись в работу.
                    </InlineAlert>
                ) : (
                    <>
                        <GlassCard className="proposal-card">
                            <h2>Что можно предложить из вашего прайса</h2>
                            <p>
                                Предварительный поиск по названию. Совместимость,
                                количество, единицы и регион требуют вашей проверки;
                                наличие и интерес победителя неизвестны.
                            </p>
                            {candidates.length ? (
                                <div className="winner-list">
                                    {candidates.map((candidate) => (
                                        <div
                                            className="winner-item"
                                            key={candidate.item.id}
                                        >
                                            <strong>{candidate.item.name}</strong>
                                            <small>
                                                Совпавшие слова:{' '}
                                                {candidate.terms.join(', ')}
                                            </small>
                                            <Button
                                                variant="ghost"
                                                disabled={
                                                    action.busy ||
                                                    selected.includes(
                                                        candidate.item.id,
                                                    ) ||
                                                    selected.length >= 10
                                                }
                                                onClick={() =>
                                                    setSelected([
                                                        ...selected,
                                                        candidate.item.id,
                                                    ])
                                                }
                                            >
                                                Выбрать позицию {candidate.item.id}
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p>
                                    Совпадений по словам нет. Можно выбрать подходящие
                                    позиции вручную и объяснить соответствие.
                                </p>
                            )}
                            {!catalog.items.length ? (
                                <InlineAlert title="В прайсе пока нет позиций">
                                    <Link href="/catalog">Заполнить мой прайс</Link>
                                </InlineAlert>
                            ) : (
                                <form
                                    className="proposal-form"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        void compose();
                                    }}
                                >
                                    <details>
                                        <summary>
                                            Все позиции прайса · выбрано{' '}
                                            {selected.length} из 10
                                        </summary>
                                        <div className="winner-list">
                                            {catalog.items.map((item) => (
                                                <label
                                                    className="proposal-check winner-item"
                                                    key={item.id}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={selected.includes(
                                                            item.id,
                                                        )}
                                                        disabled={
                                                            action.busy ||
                                                            (!selected.includes(
                                                                item.id,
                                                            ) &&
                                                                selected.length >= 10)
                                                        }
                                                        onChange={(e) =>
                                                            setSelected(
                                                                e.target.checked
                                                                    ? [
                                                                          ...selected,
                                                                          item.id,
                                                                      ]
                                                                    : selected.filter(
                                                                          (id) =>
                                                                              id !==
                                                                              item.id,
                                                                      ),
                                                            )
                                                        }
                                                    />
                                                    <span>
                                                        <strong>{item.name}</strong>
                                                        <small>
                                                            {item.characteristics ||
                                                                'Характеристики не указаны'}
                                                        </small>
                                                        <small>
                                                            Цена:{' '}
                                                            {item.price === null
                                                                ? 'не указана'
                                                                : item.price +
                                                                  ' ₽'}{' '}
                                                            · объём:{' '}
                                                            {item.quantity ??
                                                                'не указан'}{' '}
                                                            ·{' '}
                                                            {item.unit ??
                                                                'единица неизвестна'}{' '}
                                                            ·{' '}
                                                            {item.region ??
                                                                'регион неизвестен'}
                                                        </small>
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </details>
                                    <p>
                                        Выбранные позиции:{' '}
                                        {catalog.items
                                            .filter((item) =>
                                                selected.includes(item.id),
                                            )
                                            .map((item) => item.name)
                                            .join('; ') || 'пока нет'}
                                    </p>
                                    <label>
                                        Почему эти позиции подходят
                                        <textarea
                                            required
                                            rows={3}
                                            maxLength={2000}
                                            value={rationale}
                                            onChange={(e) =>
                                                setRationale(e.target.value)
                                            }
                                        />
                                    </label>
                                    <label>
                                        Ваша компания или имя
                                        <input
                                            required
                                            maxLength={500}
                                            value={company}
                                            onChange={(e) => setCompany(e.target.value)}
                                        />
                                    </label>
                                    <label>
                                        Ваши контакты
                                        <input
                                            required
                                            maxLength={1000}
                                            value={contacts}
                                            onChange={(e) =>
                                                setContacts(e.target.value)
                                            }
                                        />
                                    </label>
                                    <label className="proposal-check">
                                        <input
                                            type="checkbox"
                                            checked={confirmed}
                                            onChange={(e) =>
                                                setConfirmed(e.target.checked)
                                            }
                                        />
                                        <span>
                                            Я проверил выбранные позиции и описал
                                            основание предложения. Цены, объём и сроки
                                            нужно согласовать с исполнителем.
                                        </span>
                                    </label>
                                    {row.body ? (
                                        <label className="proposal-check">
                                            <input
                                                type="checkbox"
                                                checked={replace}
                                                onChange={(e) =>
                                                    setReplace(e.target.checked)
                                                }
                                            />
                                            <span>
                                                Заменить текущий черновик новым. Сначала
                                                сохраните нужные правки отдельно.
                                            </span>
                                        </label>
                                    ) : null}
                                    <Button
                                        type="submit"
                                        disabled={
                                            action.busy ||
                                            !selected.length ||
                                            !confirmed ||
                                            !!textDraft.pending ||
                                            (!!row.body && !replace)
                                        }
                                    >
                                        Собрать черновик обращения
                                    </Button>
                                </form>
                            )}
                        </GlassCard>
                    </>
                )}
                {row.body ? (
                    <GlassCard className="proposal-card">
                        <h2>Черновик обращения</h2>
                        {row.stale ? (
                            <InlineAlert
                                title="Черновик требует пересборки"
                                tone="warning"
                            >
                                Прайс или основание изменились. Проверьте позиции и
                                соберите новый текст.
                            </InlineAlert>
                        ) : null}
                        <details>
                            <summary>Позиции, использованные при сборке</summary>
                            {row.draft_source?.items.map((item) => (
                                <p key={item.id}>
                                    {item.name} —{' '}
                                    {item.characteristics || 'без характеристик'}
                                </p>
                            ))}
                            <p>Основание соответствия: {row.draft_source?.rationale}</p>
                        </details>
                        <div className="proposal-form">
                            <TextDraftNotice
                                draft={textDraft}
                                disabled={action.busy}
                                onResolve={() => {
                                    setReviewed(false);
                                    setCopyMessage('');
                                }}
                            />
                            <label>
                                Текст обращения
                                <textarea
                                    aria-label="Текст обращения"
                                    className="proposal-draft"
                                    maxLength={20000}
                                    rows={14}
                                    readOnly={
                                        row.archived ||
                                        action.busy ||
                                        !!textDraft.pending
                                    }
                                    value={body}
                                    onChange={(e) => {
                                        textDraft.change({ body: e.target.value });
                                        setReviewed(false);
                                        setCopyMessage('');
                                    }}
                                />
                            </label>
                            {!row.archived ? (
                                <>
                                    <label className="proposal-check">
                                        <input
                                            type="checkbox"
                                            checked={reviewed}
                                            disabled={
                                                action.busy || !!textDraft.pending
                                            }
                                            onChange={(e) =>
                                                setReviewed(e.target.checked)
                                            }
                                        />
                                        <span>
                                            Проверил правки: названия, факты и условия
                                            соответствуют моим данным.
                                        </span>
                                    </label>
                                    <Button
                                        disabled={
                                            action.busy ||
                                            row.stale ||
                                            !dirty ||
                                            !reviewed ||
                                            !body.trim() ||
                                            !!textDraft.pending
                                        }
                                        onClick={() => void saveDraft()}
                                    >
                                        Сохранить правки
                                    </Button>
                                </>
                            ) : null}
                            {dirty ? (
                                <p>
                                    Есть несохранённые правки. Копирование и TXT станут
                                    доступны после сохранения.
                                </p>
                            ) : null}
                            <div className="proposal-actions">
                                <Button
                                    variant="secondary"
                                    disabled={!canExport}
                                    onClick={() => void copy()}
                                >
                                    Скопировать сохранённый текст
                                </Button>
                                {canExport ? (
                                    <a href={root + '/export?version=' + row.version}>
                                        Скачать TXT
                                    </a>
                                ) : null}
                            </div>
                            <p role="status">{copyMessage}</p>
                        </div>
                    </GlassCard>
                ) : null}
                <GlassCard className="proposal-card">
                    <h2>Результат работы с исполнителем</h2>
                    <p>{winnerOutcomes[row.outcome]}</p>
                    <p className="proposal-text">
                        {row.outcome_note || 'Результат пока не записан.'}
                    </p>
                    <p>
                        Отметки заполняете вы. Они не подтверждают отправку площадкой,
                        получение заказа или оплату.
                    </p>
                    <div className="proposal-actions">
                        <Button
                            disabled={action.busy || row.archived || dirty}
                            onClick={() => openDecision('outcome')}
                        >
                            Записать обращение или ответ
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={action.busy || dirty}
                            onClick={() => openDecision('archive')}
                        >
                            {row.archived ? 'Вернуть в работу' : 'В архив'}
                        </Button>
                    </div>
                </GlassCard>
                <GlassCard className="proposal-card">
                    <h2>История проверок и обращений</h2>
                    <p>
                        Снимки на момент записи сохраняются отдельно от текущего
                        черновика.
                    </p>
                    {events.data.map((event) => (
                        <details key={event.id}>
                            <summary>
                                {new Date(event.created_at).toLocaleString('ru-RU', {
                                    timeZone: 'Europe/Moscow',
                                })}{' '}
                                МСК ·{' '}
                                {event.action === 'evidence'
                                    ? 'Источник проверен вами'
                                    : event.action === 'archive'
                                      ? 'Архив изменён'
                                      : winnerOutcomes[event.action]}
                            </summary>
                            <p className="proposal-text">{event.snapshot.note}</p>
                            {event.snapshot.evidence ? (
                                <>
                                    <p>
                                        {event.snapshot.evidence.winner_name} ·{' '}
                                        {event.snapshot.evidence.source_reference}
                                    </p>
                                    <a
                                        href={event.snapshot.evidence.source_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Источник на момент записи
                                    </a>
                                    <blockquote className="winner-source">
                                        {event.snapshot.evidence.source_quote}
                                    </blockquote>
                                </>
                            ) : null}
                            {event.snapshot.body ? (
                                <p className="proposal-text">{event.snapshot.body}</p>
                            ) : null}
                        </details>
                    ))}
                    <div className="proposal-actions">
                        {events.prev_page_url ? (
                            <Link href={events.prev_page_url} preserveState={false}>
                                Предыдущие записи
                            </Link>
                        ) : null}
                        {events.next_page_url ? (
                            <Link href={events.next_page_url} preserveState={false}>
                                Следующие записи
                            </Link>
                        ) : null}
                    </div>
                </GlassCard>
                <BottomSheet
                    open={editing}
                    title="Изменить основание"
                    onClose={() => setEditing(false)}
                >
                    {editing ? (
                        <WinnerEvidenceForm
                            id={row.id}
                            version={row.version}
                            evidence={row.evidence}
                            onCancel={() => setEditing(false)}
                        />
                    ) : null}
                </BottomSheet>
                <BottomSheet
                    open={decision !== null}
                    title={
                        decision === 'archive'
                            ? row.archived
                                ? 'Вернуть возможность в работу'
                                : 'Перенести возможность в архив'
                            : 'Записать результат вручную'
                    }
                    onClose={() => {
                        if (!action.busy) setDecision(null);
                    }}
                >
                    <form
                        className="proposal-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void decide();
                        }}
                    >
                        {decision === 'outcome' ? (
                            <>
                                <label>
                                    Что произошло
                                    <select
                                        value={outcome}
                                        onChange={(e) => setOutcome(e.target.value)}
                                    >
                                        {Object.entries(winnerOutcomes)
                                            .filter(([key]) => key !== 'new')
                                            .map(([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <label>
                                    Канал, дата, ссылка или содержание ответа
                                    <textarea
                                        required
                                        rows={4}
                                        maxLength={4000}
                                        value={note}
                                        onChange={(e) => setNote(e.target.value)}
                                    />
                                </label>
                                {outcome === 'contacted' ? (
                                    <p>
                                        Подтвердите, что самостоятельно отправили именно
                                        сохранённый текст. Его снимок и основания будут
                                        записаны в историю. Кнопка ничего не отправляет.
                                    </p>
                                ) : (
                                    <p>
                                        Запишите реальный результат. «Есть запрос на
                                        поставку» означает интерес исполнителя, а не
                                        заключённый договор или прибыль.
                                    </p>
                                )}
                            </>
                        ) : (
                            <p>Источник, черновик и история сохранятся.</p>
                        )}
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={decisionConfirmed}
                                onChange={(e) => setDecisionConfirmed(e.target.checked)}
                            />
                            <span>
                                Подтверждаю{' '}
                                {decision === 'archive'
                                    ? 'изменение архива'
                                    : 'достоверность ручной отметки'}
                            </span>
                        </label>
                        {action.error ? (
                            <InlineAlert title="Не удалось сохранить" tone="danger">
                                {action.error}
                            </InlineAlert>
                        ) : null}
                        <div className="proposal-actions">
                            <Button
                                type="submit"
                                disabled={action.busy || !decisionConfirmed}
                            >
                                Подтвердить запись
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={action.busy}
                                onClick={() => setDecision(null)}
                            >
                                Отмена
                            </Button>
                        </div>
                    </form>
                </BottomSheet>
            </AppShell>
        </>
    );
}
