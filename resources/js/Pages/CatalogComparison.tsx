import '../../css/price-catalog.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard } from '../Components/ui';
import { CatalogStatus, CatalogUpload } from '../Components/CatalogTools';
import {
    catalogMoney,
    matchLabels,
    useCatalogAction,
    type Catalog,
    type CatalogComparison as Comparison,
    type ComparisonRow,
    type MatchStatus,
} from '../lib/priceCatalog';
import { scopedUrl } from '../lib/workspace';
import type { PageProps } from '../types';

export default function CatalogComparisonPage() {
    const {
        catalog,
        comparison: initial,
        tender,
        teamId,
    } = usePage<
        PageProps<{
            catalog: Catalog;
            comparison: Comparison;
            tender: { id: number; title: string; canonical_url: string };
            teamId: string | null;
        }>
    >().props;
    const [comparison, setComparison] = useState(initial);
    const [filter, setFilter] = useState('all');
    const [visible, setVisible] = useState(50);
    const [clearing, setClearing] = useState(false);
    const action = useCatalogAction();
    const team = teamId ? { id: Number(teamId) } : null;
    const url = (suffix: string) =>
        scopedUrl(`/catalog/tenders/${tender.id}${suffix}`, team);
    const versionData = {
        version: comparison.version,
        catalog_version: comparison.catalog_version,
        tender_hash: comparison.tender_hash,
    };
    const rows = comparison.rows.filter(
        (row) => filter === 'all' || row.status === filter,
    );
    async function review(
        index: number,
        status: MatchStatus | 'auto',
        item: string,
        note: string,
    ) {
        const response = await action.run(
            () =>
                window.axios
                    .post<{ comparison: Comparison }>(url('/reviews'), {
                        ...versionData,
                        index,
                        status,
                        item_id: status === 'missing' || !item ? null : Number(item),
                        note: note || null,
                    })
                    .then((r) => r.data),
            'Решение сохранено.',
        );
        if (response) setComparison(response.comparison);
    }
    return (
        <>
            <Head title="Сравнение с прайсом" />
            <AppShell
                title="Сравнение с прайсом"
                eyebrow="Pro · Ultra"
                backHref="/catalog"
                activeNav="/profile"
                className="catalog-page"
            >
                <GlassCard className="catalog-section" tone="accent">
                    <h2>{tender.title}</h2>
                    <p>
                        Сравниваем с вашим личным прайсом. Проверка слов и чисел
                        помогает найти варианты, но не подтверждает техническую
                        эквивалентность.
                    </p>
                    <div className="catalog-actions">
                        <Link
                            className="catalog-link"
                            href={scopedUrl(`/tenders/${tender.id}/work`, team)}
                        >
                            Открыть заявку
                        </Link>
                        <Link className="catalog-link" href="/catalog">
                            Мой прайс · {catalog.items.length} позиций
                        </Link>
                    </div>
                </GlassCard>
                <CatalogStatus {...action} />
                {!catalog.items.length ? (
                    <GlassCard className="catalog-section">
                        <h2>Сначала заполните прайс</h2>
                        <p>
                            Добавьте свои товары или компетенции, чтобы увидеть варианты
                            сопоставления.
                        </p>
                        <Link className="catalog-link" href="/catalog">
                            Загрузить прайс →
                        </Link>
                    </GlassCard>
                ) : null}
                <GlassCard className="catalog-section">
                    <h2>
                        {comparison.has_specification
                            ? 'Ваша спецификация'
                            : 'Полный перечень позиций не передан'}
                    </h2>
                    <p>
                        {comparison.has_specification
                            ? `Файл: ${comparison.filename}. ${comparison.complete ? 'Вы отметили перечень как полный.' : 'Загружена часть перечня; выводы относятся только к ней.'}`
                            : 'Сейчас сравниваем только название закупки. Количество строк, характеристики и полноту заказа по нему определить нельзя. Для построчной проверки загрузите спецификацию в формате шаблона.'}
                    </p>
                    <CatalogUpload
                        busy={action.busy}
                        specification
                        replace={comparison.has_specification}
                        onUpload={async (file, complete) => {
                            const data = new FormData();
                            data.append('file', file);
                            data.append('complete', complete ? '1' : '0');
                            Object.entries(versionData).forEach(([key, value]) =>
                                data.append(key, String(value)),
                            );
                            const response = await action.run(
                                () =>
                                    window.axios
                                        .post<{
                                            comparison: Comparison;
                                        }>(url('/specification'), data)
                                        .then((r) => r.data),
                                'Спецификация загружена. Проверьте сопоставления.',
                            );
                            if (response) {
                                setComparison(response.comparison);
                                setVisible(50);
                            }
                            return response !== null;
                        }}
                    />
                    {comparison.has_specification ? (
                        <Button
                            variant="ghost"
                            disabled={action.busy}
                            onClick={() => setClearing(true)}
                        >
                            Убрать спецификацию
                        </Button>
                    ) : null}
                </GlassCard>
                <GlassCard className="catalog-section">
                    <h2>
                        {comparison.has_specification
                            ? `Проверка ${comparison.complete ? 'перечня' : 'загруженной части'} · ${comparison.rows.length} позиций`
                            : 'Предварительная проверка названия'}
                    </h2>
                    <div className="catalog-counts">
                        {(['matched', 'uncertain', 'missing'] as const).map(
                            (status) => (
                                <div key={status}>
                                    <strong>{comparison.counts[status]}</strong>
                                    <span>{matchLabels[status]}</span>
                                </div>
                            ),
                        )}
                    </div>
                    <p>
                        Из найденных подтверждено вами: {comparison.counts.confirmed}.
                        Остальные совпадения предварительные. «Не найдено» означает
                        отсутствие подходящего названия в этом прайсе.
                    </p>
                    <label className="form-field">
                        <span>Показать позиции</span>
                        <select
                            value={filter}
                            onChange={(e) => {
                                setFilter(e.target.value);
                                setVisible(50);
                            }}
                        >
                            <option value="all">Все позиции</option>
                            {Object.entries(matchLabels).map(([value, label]) => (
                                <option value={value} key={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                </GlassCard>
                {rows.slice(0, visible).map((row) => (
                    <Requirement
                        key={`${row.index}:${JSON.stringify(row.requirement)}:${JSON.stringify(row.review)}:${catalog.version}`}
                        row={row}
                        catalog={catalog}
                        busy={action.busy}
                        onSave={review}
                    />
                ))}
                {!rows.length ? <p>В этой группе позиций нет.</p> : null}
                {rows.length > visible ? (
                    <Button
                        variant="secondary"
                        onClick={() => setVisible(visible + 50)}
                    >
                        Показать ещё 50
                    </Button>
                ) : null}
            </AppShell>
            <BottomSheet
                open={clearing}
                onClose={() => {
                    if (!action.busy) setClearing(false);
                }}
                title="Убрать спецификацию?"
            >
                <div className="catalog-editor">
                    <p>
                        Вернётся сравнение по названию. Подтверждения позиций будут
                        сброшены.
                    </p>
                    <CatalogStatus {...action} />
                    <div className="catalog-actions">
                        <Button
                            variant="danger"
                            disabled={action.busy}
                            onClick={async () => {
                                const response = await action.run(
                                    () =>
                                        window.axios
                                            .delete<{
                                                comparison: Comparison;
                                            }>(url('/specification'), {
                                                data: versionData,
                                            })
                                            .then((r) => r.data),
                                    'Спецификация удалена.',
                                );
                                if (response) {
                                    setComparison(response.comparison);
                                    setClearing(false);
                                }
                            }}
                        >
                            Убрать
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={action.busy}
                            onClick={() => setClearing(false)}
                        >
                            Отмена
                        </Button>
                    </div>
                </div>
            </BottomSheet>
        </>
    );
}

function Requirement({
    row,
    catalog,
    busy,
    onSave,
}: {
    row: ComparisonRow;
    catalog: Catalog;
    busy: boolean;
    onSave: (
        index: number,
        status: MatchStatus | 'auto',
        item: string,
        note: string,
    ) => Promise<void>;
}) {
    const [item, setItem] = useState(
        String(row.review?.item_id ?? row.candidates[0]?.item.id ?? ''),
    );
    const [status, setStatus] = useState<MatchStatus>(row.review?.status ?? row.status);
    const [note, setNote] = useState(row.review?.note ?? '');
    const { requirement } = row;
    return (
        <GlassCard className="catalog-section catalog-requirement">
            <div className="catalog-heading">
                <h2>
                    {row.index + 1}. {requirement.name}
                </h2>
                <Badge
                    tone={
                        row.status === 'matched'
                            ? 'success'
                            : row.status === 'uncertain'
                              ? 'warning'
                              : 'neutral'
                    }
                >
                    {row.review?.status === 'matched'
                        ? 'Подтверждено вами'
                        : row.status === 'matched'
                          ? 'Предварительно найдено'
                          : matchLabels[row.status]}
                </Badge>
            </div>
            <p>{requirement.characteristics ?? 'Характеристики не переданы'}</p>
            <p>
                Нужно:{' '}
                {requirement.quantity === null
                    ? 'количество неизвестно'
                    : Number(requirement.quantity).toLocaleString('ru-RU')}{' '}
                {requirement.unit ?? ''} · {requirement.region ?? 'Регион не передан'}
            </p>
            <details className="catalog-candidates" open>
                <summary>Варианты из прайса · {row.candidates.length}</summary>
                {!row.candidates.length ? (
                    <p>
                        Совпадений названия не найдено. Можно выбрать позицию вручную.
                    </p>
                ) : null}
                {row.candidates.map((candidate) => (
                    <article className="catalog-candidate" key={candidate.item.id}>
                        <h3>{candidate.item.name}</h3>
                        <p>
                            {candidate.item.characteristics ??
                                'Характеристики не указаны'}
                        </p>
                        <p>
                            {catalogMoney(candidate.item.price)} · доступно{' '}
                            {candidate.item.quantity === null
                                ? 'неизвестно'
                                : Number(candidate.item.quantity).toLocaleString(
                                      'ru-RU',
                                  )}{' '}
                            {candidate.item.unit ?? ''} ·{' '}
                            {candidate.item.region ?? 'Регион неизвестен'}
                        </p>
                        <p>Совпали слова: {candidate.terms.join(', ')}</p>
                        <CheckList checks={candidate.checks} />
                    </article>
                ))}
                {row.selected &&
                !row.candidates.some((c) => c.item.id === row.selected?.item.id) ? (
                    <article className="catalog-candidate">
                        <h3>Выбрано вами: {row.selected.item.name}</h3>
                        <p>{catalogMoney(row.selected.item.price)}</p>
                        <CheckList checks={row.selected.checks} />
                    </article>
                ) : null}
            </details>
            {row.stock_check ? <CheckList checks={[row.stock_check]} /> : null}
            <form
                className="catalog-review"
                onSubmit={(e) => {
                    e.preventDefault();
                    void onSave(row.index, status, item, note);
                }}
            >
                <fieldset disabled={busy}>
                    <label className="form-field">
                        <span>Позиция моего прайса для строки {row.index + 1}</span>
                        <select value={item} onChange={(e) => setItem(e.target.value)}>
                            <option value="">Не выбрана</option>
                            {catalog.items.map((entry) => (
                                <option key={entry.id} value={entry.id}>
                                    {entry.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="form-field">
                        <span>Моё решение для строки {row.index + 1}</span>
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value as MatchStatus)}
                        >
                            <option value="matched">Подтверждаю соответствие</option>
                            <option value="uncertain">Нужно уточнить</option>
                            <option value="missing">Не найдено в моём каталоге</option>
                        </select>
                    </label>
                    <label className="form-field">
                        <span>Пояснение для строки {row.index + 1}</span>
                        <textarea
                            rows={2}
                            maxLength={1000}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Если данные отличаются или неизвестны, поясните подтверждение"
                        />
                    </label>
                    <div className="catalog-actions">
                        <Button
                            size="sm"
                            type="submit"
                            disabled={status === 'matched' && !item}
                        >
                            Сохранить решение
                        </Button>
                        {row.review ? (
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => void onSave(row.index, 'auto', '', '')}
                            >
                                Вернуть автоподбор
                            </Button>
                        ) : null}
                    </div>
                </fieldset>
            </form>
        </GlassCard>
    );
}
function CheckList({
    checks,
}: {
    checks: Array<{ label: string; state: string; detail: string }>;
}) {
    return (
        <ul className="catalog-checks">
            {checks.map((check) => (
                <li
                    className={`catalog-check catalog-check--${check.state}`}
                    key={check.label}
                >
                    <strong>
                        {check.state === 'ok'
                            ? '✓'
                            : check.state === 'different'
                              ? '!'
                              : '?'}{' '}
                        {check.label}
                    </strong>
                    <span>{check.detail}</span>
                </li>
            ))}
        </ul>
    );
}
