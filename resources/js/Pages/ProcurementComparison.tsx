import '../../css/proposals.css';
import '../../css/procurement.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import type { TeamScope } from '../Components/WorkspacePicker';
import {
    procurementDate,
    procurementMoney,
    procurementQuantity,
    procurementStatus,
    useProcurementAction,
    type ProcurementComparison as Comparison,
    type ProcurementOffer,
} from '../lib/procurement';
import { scopedUrl } from '../lib/workspace';
import type { PageProps } from '../types';

function OfferFields({
    offer,
    onChange,
    disabled,
    label,
}: {
    offer: ProcurementOffer;
    onChange: (next: ProcurementOffer) => void;
    disabled: boolean;
    label: string;
}) {
    const fields: {
        key: keyof ProcurementOffer;
        label: string;
        type?: string;
        max?: number;
    }[] = [
        { key: 'supplier', label: 'Поставщик', max: 160 },
        { key: 'reference', label: 'Источник цены: письмо, КП или ссылка', max: 500 },
        { key: 'unit_price', label: 'Цена за единицу, ₽', max: 9 },
        { key: 'delivery_cost', label: 'Доставка за эту закупку, ₽', max: 12 },
        { key: 'quoted_on', label: 'Дата предложения', type: 'date' },
        { key: 'valid_until', label: 'Цена действует до', type: 'date' },
        { key: 'delivery_on', label: 'Плановая дата поставки', type: 'date' },
    ];
    return (
        <fieldset className="procurement-offer proposal-form" disabled={disabled}>
            <legend>{label}</legend>
            <div className="procurement-grid">
                {fields.map((field) => (
                    <label key={field.key}>
                        {field.label}
                        <input
                            type={field.type ?? 'text'}
                            inputMode={
                                field.key === 'unit_price' ||
                                field.key === 'delivery_cost'
                                    ? 'decimal'
                                    : undefined
                            }
                            maxLength={field.max}
                            value={offer[field.key] ?? ''}
                            onChange={(event) =>
                                onChange({
                                    ...offer,
                                    [field.key]: event.target.value || null,
                                })
                            }
                        />
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

export default function ProcurementComparison() {
    const {
        comparison: initial,
        team,
        can_edit,
    } = usePage<PageProps<TeamScope & { comparison: Comparison }>>().props;
    const [comparison, setComparison] = useState(initial);
    const [offers, setOffers] = useState(initial.offers);
    const [combined, setCombined] = useState(initial.combined_offer);
    const [note, setNote] = useState(initial.conditions_note ?? '');
    const [dirty, setDirty] = useState(false);
    const [reviewed, setReviewed] = useState(false);
    const [closing, setClosing] = useState(false);
    const [closeConfirmed, setCloseConfirmed] = useState(false);
    const action = useProcurementAction();
    const root = scopedUrl(`/procurement/comparisons/${comparison.id}`, team);
    const readOnly =
        !can_edit ||
        comparison.status !== 'draft' ||
        comparison.stale_reasons.length > 0;
    const disabled = readOnly || action.busy;
    function changed() {
        setDirty(true);
        setReviewed(false);
    }
    function accept(next: Comparison) {
        setComparison(next);
        setOffers(next.offers);
        setCombined(next.combined_offer);
        setNote(next.conditions_note ?? '');
        setDirty(false);
        setReviewed(false);
    }
    async function save() {
        const result = await action.run<{ comparison: Comparison }>(
            'patch',
            root,
            {
                version: comparison.version,
                offers,
                combined_offer: combined,
                conditions_note: note || null,
            },
            'Предложения сохранены. Сравнение пересчитано.',
        );
        if (result) accept(result.comparison);
    }
    async function decide(decision: 'confirm' | 'cancel') {
        const result = await action.run<{ comparison: Comparison }>(
            'post',
            scopedUrl(`/procurement/comparisons/${comparison.id}/${decision}`, team),
            {
                version: comparison.version,
                confirmed: decision === 'confirm' ? reviewed : closeConfirmed,
            },
            decision === 'confirm'
                ? 'Расчёт подтверждён и распределён по контрактам.'
                : 'Сравнение закрыто. Потребности снова доступны для других закупок.',
        );
        if (result) {
            accept(result.comparison);
            setClosing(false);
            setCloseConfirmed(false);
        }
    }
    const result = comparison.result;
    return (
        <>
            <Head title="Сравнение закупок" />
            <AppShell
                title="Сравнение закупок"
                eyebrow={
                    team ? `Ultra · команда ${team.name}` : 'Ultra · личные контракты'
                }
                backHref={scopedUrl('/procurement', team)}
                className="feature-page"
                activeNav="/profile"
            >
                <section className="proposal-intro">
                    <Badge
                        tone={comparison.status === 'confirmed' ? 'success' : 'neutral'}
                    >
                        {procurementStatus[comparison.status]}
                    </Badge>
                    <h2>
                        {comparison.title} · {procurementQuantity(result.quantity)}{' '}
                        {result.unit}
                    </h2>
                    <p>
                        Сравнение предложений, введённых вами. Общая стоимость с
                        доставкой распределяется пропорционально количеству, до копейки.
                        Это расчёт по предложениям, а не уже полученная прибыль.
                    </p>
                </section>
                {action.error ? (
                    <InlineAlert title="Не удалось выполнить действие" tone="danger">
                        {action.error}
                    </InlineAlert>
                ) : null}
                {action.message ? (
                    <InlineAlert title="Готово" tone="success">
                        {action.message}
                    </InlineAlert>
                ) : null}
                {!can_edit ? (
                    <InlineAlert title="Только просмотр">
                        Изменения доступны редактору в действующей команде.
                    </InlineAlert>
                ) : null}
                {comparison.stale_reasons.length ? (
                    <InlineAlert title="Исходные потребности изменились" tone="warning">
                        {comparison.stale_reasons.join(' ')} Сохранённый снимок доступен
                        для просмотра; закрытие освободит распределённые потребности.
                    </InlineAlert>
                ) : null}
                {dirty ? (
                    <InlineAlert title="Есть несохранённые правки" tone="warning">
                        Показатели относятся к последнему сохранению. Сохраните
                        предложения перед подтверждением.
                    </InlineAlert>
                ) : null}
                {result.different_specs || result.different_dates ? (
                    <InlineAlert title="Проверьте совместимость" tone="warning">
                        {result.different_specs
                            ? 'Характеристики позиций отличаются. '
                            : ''}
                        {result.different_dates
                            ? 'Сроки потребностей различаются. '
                            : ''}
                        Общая поставка должна подходить всем контрактам и прибыть не
                        позже самого раннего срока.
                    </InlineAlert>
                ) : null}
                {comparison.status === 'confirmed' &&
                comparison.current_reasons.length ? (
                    <InlineAlert
                        title="Условия требуют повторной проверки"
                        tone="warning"
                    >
                        Снимок подтверждённого расчёта сохранён.{' '}
                        {comparison.current_reasons.join(' ')}
                    </InlineAlert>
                ) : null}
                <GlassCard className="proposal-card">
                    <h2>Стоимость и экономия</h2>
                    <div className="procurement-metrics">
                        <div>
                            <span>Отдельные закупки</span>
                            <strong>{procurementMoney(result.separate_total)}</strong>
                        </div>
                        <div>
                            <span>Общая закупка</span>
                            <strong>{procurementMoney(result.combined_total)}</strong>
                        </div>
                        <div>
                            <span>
                                {result.complete && Number(result.saving) < 0
                                    ? 'Увеличение расходов'
                                    : 'Расчётная экономия'}
                            </span>
                            <strong>
                                {result.complete
                                    ? procurementMoney(
                                          result.saving?.replace(/^-/, '') ?? null,
                                      )
                                    : 'Расчёт не завершён'}
                            </strong>
                        </div>
                    </div>
                    <p>
                        В обе суммы включена доставка. Цена за единицу должна учитывать
                        одинаковый состав расходов, налоги и комплектацию. Нулевую
                        доставку указывайте явно; пустое поле означает «неизвестно».
                    </p>
                    {!result.complete ? (
                        <details className="procurement-missing">
                            <summary>
                                Что нужно уточнить ({result.reasons.length})
                            </summary>
                            <ul>
                                {result.reasons.map((reason, i) => (
                                    <li key={i}>{reason}</li>
                                ))}
                            </ul>
                        </details>
                    ) : null}
                    {result.complete && Number(result.saving) <= 0 ? (
                        <InlineAlert
                            title="Объединение не снижает расходы"
                            tone="warning"
                        >
                            Можно сохранить предложения для сравнения. Для подтверждения
                            распределения нужна положительная экономия.
                        </InlineAlert>
                    ) : null}
                    {comparison.status === 'canceled' ? (
                        <InlineAlert title="Этот расчёт не учитывается">
                            Сравнение закрыто. Снимок сохранён для истории; потребности
                            могут входить в новую закупку.
                        </InlineAlert>
                    ) : null}
                </GlassCard>
                <GlassCard className="proposal-card">
                    <h2>Потребности и распределение</h2>
                    <p>
                        Сумма распределения точно равна общей закупке. В одном контракте
                        экономия может быть меньше или появиться доплата — проверьте
                        каждую строку.
                    </p>
                    {comparison.selection.map((need) => {
                        const allocation = result.allocations.find(
                            (row) => row.need_id === need.id,
                        )!;
                        return (
                            <div className="procurement-allocation" key={need.id}>
                                <strong>{need.contract}</strong>
                                <p>
                                    {need.title} · {procurementQuantity(need.quantity)}{' '}
                                    {need.unit} · {procurementDate(need.required_on)}
                                </p>
                                <p className="procurement-spec">
                                    {need.specification || 'Характеристики не указаны'}
                                </p>
                                <dl>
                                    <div>
                                        <dt>Отдельно с доставкой</dt>
                                        <dd>
                                            {procurementMoney(allocation.separate_cost)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Доля общей закупки</dt>
                                        <dd>
                                            {procurementMoney(allocation.combined_cost)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>
                                            {Number(allocation.saving) < 0
                                                ? 'Доплата по контракту'
                                                : 'Экономия по контракту'}
                                        </dt>
                                        <dd>
                                            {procurementMoney(
                                                allocation.saving?.replace(/^-/, '') ??
                                                    null,
                                            )}
                                        </dd>
                                    </div>
                                </dl>
                                <Link
                                    href={scopedUrl(
                                        `/tenders/${need.tender_id}/work`,
                                        team,
                                    )}
                                >
                                    Открыть исходную заявку →
                                </Link>
                            </div>
                        );
                    })}
                    <p>
                        Этот расчёт сохраняется отдельно. Он автоматически не уменьшает
                        расходы в экономике заявки или контроле исполнения. Если
                        перенесёте новые суммы туда, замените соответствующую оценку, а
                        не добавляйте экономию второй раз.
                    </p>
                </GlassCard>
                <form
                    className="proposal-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void save();
                    }}
                >
                    <h2 className="procurement-heading">
                        Предложения на отдельные закупки
                    </h2>
                    {comparison.selection.map((need, i) => (
                        <GlassCard className="proposal-card" key={need.id}>
                            <details open={i === 0}>
                                <summary>
                                    {need.contract} ·{' '}
                                    {procurementQuantity(need.quantity)} {need.unit}
                                </summary>
                                <OfferFields
                                    label={`Отдельно: ${need.contract}`}
                                    offer={offers.find(
                                        (offer) => offer.need_id === need.id,
                                    )!}
                                    disabled={disabled}
                                    onChange={(next) => {
                                        setOffers(
                                            offers.map((offer) =>
                                                offer.need_id === need.id
                                                    ? { ...next, need_id: need.id }
                                                    : offer,
                                            ),
                                        );
                                        changed();
                                    }}
                                />
                            </details>
                        </GlassCard>
                    ))}
                    <GlassCard className="proposal-card">
                        <h2>Предложение на общую закупку</h2>
                        <p>
                            {procurementQuantity(result.quantity)} {result.unit}.
                            Доставка вводится один раз за всю общую закупку.
                        </p>
                        <OfferFields
                            label="Общая закупка"
                            offer={combined}
                            disabled={disabled}
                            onChange={(next) => {
                                setCombined(next);
                                changed();
                            }}
                        />
                        <label>
                            Сопоставимые условия и проверенные различия
                            <textarea
                                rows={3}
                                maxLength={2000}
                                value={note}
                                disabled={disabled}
                                onChange={(event) => {
                                    setNote(event.target.value);
                                    changed();
                                }}
                                placeholder="Например: одинаковая модель, цены с НДС; обеим заявкам подходит поставка до 10 ноября. Доставка до одного склада."
                            />
                        </label>
                        <Button type="submit" disabled={disabled || !dirty}>
                            {action.busy
                                ? 'Сохраняем…'
                                : 'Сохранить предложения и пересчитать'}
                        </Button>
                        {action.error ? (
                            <InlineAlert title="Проверьте данные" tone="danger">
                                {action.error}
                            </InlineAlert>
                        ) : null}
                    </GlassCard>
                </form>
                {comparison.status === 'draft' ? (
                    <GlassCard className="proposal-card proposal-form">
                        <h2>Подтвердить распределение</h2>
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={reviewed}
                                disabled={
                                    disabled ||
                                    dirty ||
                                    !result.complete ||
                                    Number(result.saving) <= 0
                                }
                                onChange={(event) => setReviewed(event.target.checked)}
                            />
                            <span>
                                Проверил реальные предложения поставщиков, совместимость
                                характеристик, налоги, комплектацию, сроки и
                                распределение по контрактам.
                            </span>
                        </label>
                        <p>
                            Подтверждённые потребности не смогут одновременно попасть в
                            другую общую закупку. Заказ поставщику не отправляется.
                        </p>
                        <Button
                            disabled={disabled || dirty || !reviewed}
                            onClick={() => decide('confirm')}
                        >
                            Подтвердить и распределить
                        </Button>
                    </GlassCard>
                ) : null}
                {can_edit && comparison.status !== 'canceled' ? (
                    <div className="proposal-actions">
                        <Button
                            variant="secondary"
                            disabled={action.busy}
                            onClick={() => {
                                setClosing(true);
                                setCloseConfirmed(false);
                            }}
                        >
                            {comparison.status === 'confirmed'
                                ? 'Отменить подтверждение'
                                : 'Закрыть сравнение'}
                        </Button>
                    </div>
                ) : null}
                <BottomSheet
                    open={closing}
                    onClose={() => {
                        if (!action.busy) setClosing(false);
                    }}
                    title="Закрыть сравнение?"
                >
                    <div className="proposal-form">
                        <p>
                            Снимок останется в истории. Подтверждённое распределение
                            перестанет учитываться, а потребности снова станут доступны
                            для сравнения.
                        </p>
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={closeConfirmed}
                                disabled={action.busy}
                                onChange={(event) =>
                                    setCloseConfirmed(event.target.checked)
                                }
                            />
                            <span>
                                Понимаю, что это распределение больше не будет
                                действовать.
                            </span>
                        </label>
                        {action.error ? (
                            <InlineAlert title="Не удалось закрыть" tone="danger">
                                {action.error}
                            </InlineAlert>
                        ) : null}
                        <Button
                            disabled={action.busy || !closeConfirmed}
                            onClick={() => decide('cancel')}
                        >
                            Закрыть и освободить потребности
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={action.busy}
                            onClick={() => setClosing(false)}
                        >
                            Оставить сравнение
                        </Button>
                    </div>
                </BottomSheet>
            </AppShell>
        </>
    );
}
