import '../../css/proposals.css';
import '../../css/repeat-tenders.css';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import { RepeatTenderLookup } from '../Components/RepeatTenderLookup';
import { useProposalAction } from '../lib/proposals';
import {
    repeatDate,
    repeatStatus,
    safeRepeatUrl,
    type RepeatCandidate,
    type RepeatSnapshot,
    type RepeatWatch,
    type RepeatPage,
} from '../lib/repeatTenders';
import type { PageProps } from '../types';

type Props = { watch: RepeatWatch; candidates: RepeatPage<RepeatCandidate> };
const compareFields: { key: keyof RepeatSnapshot; title: string; date?: boolean }[] = [
    { key: 'customer', title: 'Заказчик' },
    { key: 'region', title: 'Регион' },
    { key: 'budget_amount', title: 'Бюджет' },
    { key: 'currency', title: 'Валюта' },
    { key: 'deadline_at', title: 'Окончание подачи', date: true },
    { key: 'stage', title: 'Этап по источнику' },
    { key: 'description', title: 'Описание карточки' },
];
function Comparison({
    original,
    newer,
}: {
    original: RepeatSnapshot;
    newer: RepeatSnapshot;
}) {
    return (
        <div className="repeat-diff">
            {compareFields.map((field) => {
                const before = original[field.key];
                const after = newer[field.key];
                const known = before !== null && after !== null;
                const text = (value: typeof before) =>
                    value === null
                        ? 'Не указано'
                        : field.date
                          ? repeatDate(String(value))
                          : String(value);
                return (
                    <div className="repeat-diff-row" key={field.key}>
                        <strong>
                            {field.title} ·{' '}
                            {known
                                ? before === after
                                    ? 'без изменений'
                                    : 'различается в карточках'
                                : 'не хватает данных для сравнения'}
                        </strong>
                        <div className="repeat-columns">
                            <div>
                                <small>Прежняя закупка</small>
                                <p>{text(before)}</p>
                            </div>
                            <div>
                                <small>Новое объявление</small>
                                <p>{text(after)}</p>
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
function SnapshotLinks({
    snapshot,
    available,
}: {
    snapshot: RepeatSnapshot;
    available: boolean;
}) {
    return (
        <>
            <h2>{snapshot.title}</h2>
            <p>
                {snapshot.reg_number || 'Номер не передан'} · опубликовано:{' '}
                {repeatDate(snapshot.published_at)}
            </p>
            <div className="proposal-actions">
                {safeRepeatUrl(snapshot.url) ? (
                    <a
                        href={safeRepeatUrl(snapshot.url)}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Оригинал объявления
                    </a>
                ) : null}
                {available ? (
                    <Link href={`/tenders/${snapshot.id}`}>Карточка в приложении</Link>
                ) : null}
            </div>
        </>
    );
}
export default function RepeatTenderWatch() {
    const { watch, candidates } = usePage<PageProps<Props>>().props;
    const [reason, setReason] = useState(watch.reason);
    const [reasonConfirmed, setReasonConfirmed] = useState(false);
    const [dialog, setDialog] = useState<
        'archive' | 'add' | 'confirm' | 'dismiss' | null
    >(null);
    const [candidate, setCandidate] = useState<RepeatCandidate | null>(null);
    const [selected, setSelected] = useState<RepeatSnapshot | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [note, setNote] = useState('');
    const [sourceUrl, setSourceUrl] = useState('');
    const [reference, setReference] = useState('');
    const [quote, setQuote] = useState('');
    const [checkedOn, setCheckedOn] = useState('');
    const [previousOutcome, setPreviousOutcome] = useState('');
    const action = useProposalAction();
    const root = '/repeat-tenders/' + watch.id;
    const editable = !watch.archived && watch.available;
    function open(kind: typeof dialog, c: RepeatCandidate | null = null) {
        setDialog(kind);
        setCandidate(c);
        setConfirmed(false);
        setNote('');
        setSelected(null);
        setSourceUrl('');
        setReference('');
        setQuote('');
        setCheckedOn('');
        setPreviousOutcome('');
    }
    function reload() {
        router.visit(window.location.pathname + window.location.search, {
            preserveScroll: true,
            preserveState: false,
        });
    }
    async function saveReason() {
        if (
            await action.run(
                'patch',
                root,
                { version: watch.version, reason, confirmed: reasonConfirmed },
                'Причина сохранена.',
            )
        )
            reload();
    }
    async function scan() {
        if (await action.run('post', root + '/scan', {}, 'Проверка завершена.'))
            reload();
    }
    async function decide() {
        const path =
            dialog === 'archive'
                ? root + '/archive'
                : dialog === 'add'
                  ? root + '/candidates'
                  : root + `/candidates/${candidate?.id}/${dialog}`;
        const data =
            dialog === 'archive'
                ? { version: watch.version, archived: !watch.archived, confirmed }
                : dialog === 'add'
                  ? { tender_id: selected?.id }
                  : {
                        version: candidate?.version,
                        watch_version: watch.version,
                        confirmed,
                        latest_hash: candidate?.latest_hash,
                        source_url: sourceUrl,
                        source_reference: reference,
                        quote,
                        checked_on: checkedOn,
                        previous_outcome: previousOutcome,
                        note,
                    };
        if (
            await action.run(
                dialog === 'archive' ? 'patch' : 'post',
                path,
                data,
                'Изменения сохранены.',
            )
        ) {
            setDialog(null);
            reload();
        }
    }
    async function read(c: RepeatCandidate) {
        if (
            await action.run(
                'post',
                root + `/candidates/${c.id}/read`,
                { version: c.version, confirmed: true },
                'Уведомление прочитано.',
            )
        )
            reload();
    }
    return (
        <>
            <Head title="Наблюдение за повторной закупкой" />
            <AppShell
                title="Повторная закупка"
                eyebrow="Личное · Ultra"
                backHref="/repeat-tenders"
                className="feature-page repeat-page"
                activeNav="/profile"
            >
                <section className="proposal-intro">
                    <Badge>
                        {watch.archived ? 'Наблюдение в архиве' : 'Наблюдение включено'}
                    </Badge>
                    <h2>{watch.original.title}</h2>
                    <p>
                        Сравните найденные объявления с причиной прошлого отказа.
                        Первоначальные поля сохранены; их последующее обновление в
                        источнике не заменяет этот снимок.
                    </p>
                </section>
                {action.error ? (
                    <InlineAlert title="Изменения не сохранены" tone="danger">
                        {action.error}{' '}
                        <Link href={root} preserveState={false}>
                            Обновить страницу
                        </Link>
                    </InlineAlert>
                ) : null}
                {!watch.available ? (
                    <InlineAlert title="Исходная закупка недоступна" tone="warning">
                        Снимок и результаты сохранены. Новые проверки и изменения
                        закрыты; можно перенести наблюдение в архив.
                    </InlineAlert>
                ) : null}
                <GlassCard className="proposal-card">
                    <h2>Почему не участвовали или не прошли</h2>
                    <div className="proposal-form">
                        <label>
                            Причина прошлого отказа
                            <textarea
                                aria-label="Причина прошлого отказа"
                                rows={4}
                                maxLength={3000}
                                readOnly={!editable}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                        </label>
                        {editable ? (
                            <>
                                <label className="proposal-check">
                                    <input
                                        type="checkbox"
                                        checked={reasonConfirmed}
                                        onChange={(e) =>
                                            setReasonConfirmed(e.target.checked)
                                        }
                                    />
                                    <span>
                                        Подтверждаю исправление своей причины отказа.
                                    </span>
                                </label>
                                <Button
                                    disabled={
                                        action.busy ||
                                        !reasonConfirmed ||
                                        reason === watch.reason ||
                                        !reason.trim()
                                    }
                                    onClick={() => void saveReason()}
                                >
                                    Сохранить причину
                                </Button>
                            </>
                        ) : null}
                    </div>
                    <div className="proposal-actions">
                        <Button
                            disabled={
                                action.busy || !editable || reason !== watch.reason
                            }
                            onClick={() => void scan()}
                        >
                            Проверить новые карточки
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={
                                action.busy || !editable || reason !== watch.reason
                            }
                            onClick={() => open('add')}
                        >
                            Связать объявление вручную
                        </Button>
                        <Button
                            variant="ghost"
                            disabled={action.busy || reason !== watch.reason}
                            onClick={() => open('archive')}
                        >
                            {watch.archived ? 'Вернуть наблюдение' : 'В архив'}
                        </Button>
                    </div>
                    <p>
                        Последняя проверка: {repeatDate(watch.checked_at)}.
                        Автоматическая проверка — раз в час, по поступлениям ваших
                        активных мониторингов после начала наблюдения, в пределах 30
                        дней и 300 последних карточек. Уведомления — внутри приложения.
                    </p>
                    {watch.scan_limited ? (
                        <InlineAlert title="Проверка ограничена" tone="warning">
                            В выборке больше 300 карточек или уже 100 непроверенных
                            совпадений. Разберите найденное и при необходимости свяжите
                            нужное объявление вручную.
                        </InlineAlert>
                    ) : null}
                </GlassCard>
                <GlassCard className="proposal-card">
                    <details>
                        <summary>Сохранённая прежняя закупка и её условия</summary>
                        <p>Снимок сохранён: {repeatDate(watch.captured_at)}</p>
                        <SnapshotLinks
                            snapshot={watch.original}
                            available={watch.available}
                        />
                        <p>
                            Бюджет: {watch.original.budget_amount ?? 'не указан'}{' '}
                            {watch.original.currency ?? ''} · окончание подачи:{' '}
                            {repeatDate(watch.original.deadline_at)}
                        </p>
                        <p className="proposal-text">
                            {watch.original.description || 'Описание не передано'}
                        </p>
                        {watch.original.description_limited ? (
                            <p>Описание сохранено в пределах первых 12 000 символов.</p>
                        ) : null}
                    </details>
                </GlassCard>
                {!candidates.data.length ? (
                    <GlassCard className="proposal-card">
                        <h2>Совпадений пока нет</h2>
                        <p>
                            Отсутствие подсказок не означает, что закупку не объявляли
                            заново. Нужное извещение должно попасть в вашу ленту. Если
                            знаете его, свяжите вручную.
                        </p>
                    </GlassCard>
                ) : null}
                {candidates.data.map((c) => {
                    const newer = c.evidence ? c.snapshot : (c.latest ?? c.snapshot);
                    const late =
                        newer.deadline_at !== null &&
                        new Date(newer.deadline_at).getTime() < Date.now();
                    const dateConflict =
                        newer.published_at !== null &&
                        watch.original.published_at !== null &&
                        new Date(newer.published_at) <=
                            new Date(watch.original.published_at);
                    return (
                        <GlassCard className="proposal-card" key={c.id}>
                            <Badge
                                tone={
                                    c.status === 'confirmed'
                                        ? 'success'
                                        : c.status === 'possible'
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {repeatStatus[c.status]}
                            </Badge>
                            {c.unread && c.status !== 'dismissed' ? (
                                <p>Новое уведомление</p>
                            ) : null}
                            <SnapshotLinks
                                snapshot={newer}
                                available={c.latest !== null}
                            />
                            <p>
                                {c.basis.kind === 'manual'
                                    ? 'Объявление выбрано вами вручную; связь ещё требует источника.'
                                    : `При обнаружении совпали заказчик и слова «${c.basis.terms.join(', ')}». Это не доказательство повторной процедуры.`}
                            </p>
                            {late ? (
                                <InlineAlert
                                    title="Срок подачи уже прошёл"
                                    tone="warning"
                                >
                                    Проверьте актуальный этап по оригиналу. Сохранённое
                                    сравнение остаётся доступно.
                                </InlineAlert>
                            ) : null}
                            {dateConflict ||
                            newer.published_at === null ||
                            watch.original.published_at === null ? (
                                <InlineAlert
                                    title="Порядок публикаций требует проверки"
                                    tone="warning"
                                >
                                    Даты отсутствуют или новое объявление опубликовано
                                    не позже прежнего. Для подтверждения нужны
                                    проверенные основания связи.
                                </InlineAlert>
                            ) : null}
                            {c.latest === null ? (
                                <InlineAlert
                                    title="Новая закупка недоступна"
                                    tone="warning"
                                >
                                    Показан личный сохранённый снимок. Подтверждение
                                    закрыто до восстановления доступа к карточке.
                                </InlineAlert>
                            ) : null}
                            {c.changed ? (
                                <InlineAlert
                                    title={
                                        c.evidence
                                            ? 'Карточка изменилась после подтверждения'
                                            : 'Карточка обновилась после обнаружения'
                                    }
                                    tone="warning"
                                >
                                    {c.evidence
                                        ? 'Ниже зафиксированы условия на момент вашей проверки. Откройте актуальную карточку и сверьте новые сведения отдельно.'
                                        : 'Ниже показаны последние доступные поля. Сверьте их с источником перед подтверждением.'}
                                </InlineAlert>
                            ) : null}
                            <details>
                                <summary>Сравнить условия двух объявлений</summary>
                                <p>
                                    Сопоставьте изменения с причиной прошлого отказа:{' '}
                                    {watch.reason}
                                </p>
                                <Comparison original={watch.original} newer={newer} />
                                {watch.original.description_limited ||
                                newer.description_limited ? (
                                    <p>
                                        Длинные описания ограничены 12 000 символами;
                                        изменения за этим пределом здесь не показаны.
                                    </p>
                                ) : null}
                                <p>
                                    Здесь сравниваются поля карточек. Комплектацию,
                                    сроки исполнения и прочие условия проверяйте в
                                    полной документации по обеим ссылкам.
                                </p>
                            </details>
                            {c.evidence ? (
                                <details>
                                    <summary>Основание подтверждения связи</summary>
                                    <p>
                                        Проверено вами {c.evidence.checked_on} ·{' '}
                                        {c.evidence.source_reference}
                                    </p>
                                    <a
                                        href={safeRepeatUrl(c.evidence.source_url)}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Источник связи
                                    </a>
                                    <blockquote className="repeat-quote">
                                        {c.evidence.quote}
                                    </blockquote>
                                    <p>
                                        Чем завершилась прежняя закупка:{' '}
                                        {c.evidence.previous_outcome}
                                    </p>
                                    <p className="proposal-text">{c.evidence.note}</p>
                                    <p>
                                        Причина отказа при подтверждении:{' '}
                                        {c.evidence.reason_at_confirmation}
                                    </p>
                                </details>
                            ) : null}
                            {c.decision_note ? (
                                <p className="proposal-text">
                                    Причина отклонения: {c.decision_note}
                                </p>
                            ) : null}
                            <div className="proposal-actions">
                                {c.status === 'possible' ? (
                                    <Button
                                        disabled={
                                            action.busy ||
                                            !editable ||
                                            !c.latest ||
                                            reason !== watch.reason
                                        }
                                        aria-label={`Подтвердить связь: ${c.snapshot.title}`}
                                        onClick={() => open('confirm', c)}
                                    >
                                        Подтвердить связь
                                    </Button>
                                ) : null}
                                {c.status !== 'dismissed' ? (
                                    <Button
                                        variant="secondary"
                                        disabled={
                                            action.busy ||
                                            !editable ||
                                            reason !== watch.reason
                                        }
                                        aria-label={`${c.evidence ? 'Отметить ошибку связи' : 'Отклонить совпадение'}: ${c.snapshot.title}`}
                                        onClick={() => open('dismiss', c)}
                                    >
                                        {c.evidence
                                            ? 'Отметить ошибку связи'
                                            : 'Отклонить совпадение'}
                                    </Button>
                                ) : null}
                                {c.unread && c.status !== 'dismissed' ? (
                                    <Button
                                        variant="ghost"
                                        disabled={
                                            action.busy ||
                                            !editable ||
                                            reason !== watch.reason
                                        }
                                        aria-label={`Отметить прочитанным: ${c.snapshot.title}`}
                                        onClick={() => void read(c)}
                                    >
                                        Отметить прочитанным
                                    </Button>
                                ) : null}
                                {c.latest ? (
                                    <Link href={`/tenders/${c.latest.id}/work`}>
                                        Оценить участие в новом объявлении
                                    </Link>
                                ) : null}
                            </div>
                        </GlassCard>
                    );
                })}
                <div className="proposal-actions">
                    {candidates.prev_page_url ? (
                        <Link href={candidates.prev_page_url} preserveState={false}>
                            Предыдущие совпадения
                        </Link>
                    ) : null}
                    {candidates.next_page_url ? (
                        <Link href={candidates.next_page_url} preserveState={false}>
                            Следующие совпадения
                        </Link>
                    ) : null}
                </div>
                <BottomSheet
                    open={dialog !== null}
                    title={
                        dialog === 'archive'
                            ? watch.archived
                                ? 'Вернуть наблюдение'
                                : 'В архив'
                            : dialog === 'add'
                              ? 'Выбрать другое объявление'
                              : dialog === 'confirm'
                                ? 'Подтвердить повторную закупку'
                                : 'Отклонить связь'
                    }
                    onClose={() => {
                        if (!action.busy) setDialog(null);
                    }}
                >
                    <form
                        className="proposal-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void decide();
                        }}
                    >
                        {dialog === 'add' ? (
                            <>
                                <RepeatTenderLookup
                                    disabled={action.busy}
                                    onSelect={setSelected}
                                />
                                {selected ? <p>Выбрано: {selected.title}</p> : null}
                                <p>
                                    Сначала запись появится как гипотеза. Подтверждение
                                    выполняется отдельно, со ссылкой и цитатой.
                                </p>
                            </>
                        ) : null}
                        {dialog === 'archive' ? (
                            <p>
                                Исходный снимок, причина и результаты сохранятся. После
                                восстановления проверка начнётся с новых поступлений.
                            </p>
                        ) : null}
                        {dialog === 'confirm' ? (
                            <>
                                <p>
                                    Сверьте, что источник связывает именно эти два
                                    извещения. Совпадения названия, бюджета и заказчика
                                    недостаточно.
                                </p>
                                <label>
                                    HTTPS-ссылка, подтверждающая связь
                                    <input
                                        aria-label="HTTPS-ссылка, подтверждающая связь"
                                        type="url"
                                        required
                                        maxLength={2000}
                                        value={sourceUrl}
                                        onChange={(e) => setSourceUrl(e.target.value)}
                                    />
                                </label>
                                <label>
                                    Документ и место цитаты
                                    <input
                                        required
                                        maxLength={500}
                                        value={reference}
                                        onChange={(e) => setReference(e.target.value)}
                                    />
                                </label>
                                <label>
                                    Цитата о повторном объявлении
                                    <textarea
                                        aria-label="Цитата о повторном объявлении"
                                        required
                                        rows={4}
                                        maxLength={6000}
                                        value={quote}
                                        onChange={(e) => setQuote(e.target.value)}
                                    />
                                </label>
                                <label>
                                    Дата проверки
                                    <input
                                        type="date"
                                        required
                                        value={checkedOn}
                                        onChange={(e) => setCheckedOn(e.target.value)}
                                    />
                                </label>
                                <label>
                                    Чем завершилась прежняя закупка
                                    <input
                                        required
                                        maxLength={1000}
                                        value={previousOutcome}
                                        onChange={(e) =>
                                            setPreviousOutcome(e.target.value)
                                        }
                                    />
                                </label>
                                <p>
                                    Укажите подтверждённый результат или явно напишите,
                                    что причина завершения неизвестна. Решение не
                                    участвовать и результат процедуры — разные сведения.
                                </p>
                            </>
                        ) : null}
                        {dialog === 'confirm' || dialog === 'dismiss' ? (
                            <label>
                                {dialog === 'confirm'
                                    ? 'Почему источник подтверждает связь'
                                    : 'Почему связь отклонена'}
                                <textarea
                                    aria-label={
                                        dialog === 'confirm'
                                            ? 'Почему источник подтверждает связь'
                                            : 'Почему связь отклонена'
                                    }
                                    required
                                    rows={3}
                                    maxLength={3000}
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                            </label>
                        ) : null}
                        {dialog !== 'add' ? (
                            <label className="proposal-check">
                                <input
                                    type="checkbox"
                                    checked={confirmed}
                                    onChange={(e) => setConfirmed(e.target.checked)}
                                />
                                <span>
                                    {dialog === 'confirm'
                                        ? 'Сверил источник и оба извещения, проверил различия и неизвестные сведения. Подтверждение будет моей ручной отметкой.'
                                        : 'Подтверждаю решение.'}
                                </span>
                            </label>
                        ) : null}
                        {action.error ? (
                            <InlineAlert title="Не удалось сохранить" tone="danger">
                                {action.error}
                            </InlineAlert>
                        ) : null}
                        <div className="proposal-actions">
                            <Button
                                type="submit"
                                disabled={
                                    action.busy ||
                                    (dialog === 'add' ? !selected : !confirmed)
                                }
                            >
                                Сохранить решение
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={action.busy}
                                onClick={() => setDialog(null)}
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
