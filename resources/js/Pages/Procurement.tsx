import '../../css/proposals.css';
import '../../css/procurement.css';
import '../../css/tender-work.css';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { WorkspacePicker, type TeamScope } from '../Components/WorkspacePicker';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import {
    procurementDate,
    procurementQuantity,
    procurementStatus,
    useProcurementAction,
    type ProcurementNeed,
} from '../lib/procurement';
import { scopedUrl } from '../lib/workspace';
import type { PageProps } from '../types';

type Props = TeamScope & {
    enabled: boolean;
    needs: ProcurementNeed[];
    contracts: { id: number; title: string }[];
    moreContracts: boolean;
    units: string[];
    candidates: {
        title: string;
        unit: string;
        quantity: string;
        need_ids: number[];
        different_specs: boolean;
        different_dates: boolean;
    }[];
    comparisons: {
        data: { id: number; title: string; status: keyof typeof procurementStatus }[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

export default function Procurement() {
    const {
        enabled,
        needs,
        contracts,
        units,
        candidates,
        comparisons,
        moreContracts,
        team,
        can_edit,
    } = usePage<PageProps<Props>>().props;
    const [selected, setSelected] = useState<number[]>([]);
    const [editing, setEditing] = useState<ProcurementNeed | null | undefined>();
    const [deleting, setDeleting] = useState<ProcurementNeed | null>(null);
    const [contract, setContract] = useState(contracts[0]?.id ?? 0);
    const [title, setTitle] = useState('');
    const [specification, setSpecification] = useState('');
    const [unit, setUnit] = useState('шт');
    const [quantity, setQuantity] = useState('');
    const [requiredOn, setRequiredOn] = useState('');
    const action = useProcurementAction();
    const url = (path: string) => scopedUrl(path, team);
    function edit(need: ProcurementNeed | null) {
        setEditing(need);
        setContract(need?.participation_id ?? contracts[0]?.id ?? 0);
        setTitle(need?.title ?? '');
        setSpecification(need?.specification ?? '');
        setUnit(need?.unit ?? 'шт');
        setQuantity(need?.quantity ?? '');
        setRequiredOn(need?.required_on ?? '');
    }
    async function save() {
        const result = await action.run(
            editing ? 'patch' : 'post',
            url(editing ? `/procurement/needs/${editing.id}` : '/procurement/needs'),
            {
                version: editing?.version ?? 0,
                participation_id: contract,
                title,
                specification: specification || null,
                unit,
                quantity,
                required_on: requiredOn || null,
            },
            'Потребность сохранена.',
        );
        if (result) {
            setEditing(undefined);
            setSelected([]);
            router.reload({
                only: ['needs', 'candidates', 'contracts', 'comparisons'],
            });
        }
    }
    async function remove() {
        if (!deleting) return;
        const result = await action.run(
            'delete',
            url(`/procurement/needs/${deleting.id}`),
            { version: deleting.version },
            'Потребность удалена. Старые сравнения сохраняются в истории.',
        );
        if (result) {
            setDeleting(null);
            setSelected([]);
            router.reload({ only: ['needs', 'candidates'] });
        }
    }
    async function compare() {
        const result = await action.run<{ id: number }>(
            'post',
            url('/procurement/comparisons'),
            {
                needs: needs
                    .filter((n) => selected.includes(n.id))
                    .map((n) => ({ id: n.id, version: n.version })),
            },
            'Сравнение создано.',
        );
        if (result) router.get(url(`/procurement/comparisons/${result.id}`));
    }
    function choose(ids: number[]) {
        const seen = new Set<number>();
        const chosen: number[] = [];
        for (const need of needs) {
            if (!ids.includes(need.id) || seen.has(need.participation_id)) continue;
            seen.add(need.participation_id);
            chosen.push(need.id);
            if (chosen.length === 20) break;
        }
        setSelected(chosen);
    }
    return (
        <>
            <Head title="Объединение закупок" />
            <AppShell
                className="procurement-page"
                title="Объединение закупок"
                eyebrow="Ultra · закупайте для нескольких контрактов"
                backHref="/profile"
            >
                <WorkspacePicker path="/procurement" />
                <section className="proposal-intro">
                    <h2>Одна закупка для нескольких контрактов</h2>
                    <p>
                        Соберите потребности своих выигранных заявок. Например, 40 и 60
                        одинаковых деталей — повод запросить цену на 100. Экономия
                        появится после ввода реальных предложений с доставкой.
                    </p>
                </section>
                {!enabled ? (
                    <InlineAlert title="Доступно в Ultra">
                        Для расчётов нужен действующий Ultra.{' '}
                        <Link href="/plans">Мой доступ</Link>
                    </InlineAlert>
                ) : (
                    <>
                        {action.error ? (
                            <InlineAlert
                                title="Не удалось выполнить действие"
                                tone="danger"
                            >
                                {action.error}
                            </InlineAlert>
                        ) : null}
                        {action.message ? (
                            <p role="status" className="procurement-status">
                                {action.message}
                            </p>
                        ) : null}
                        <div className="proposal-actions">
                            <Button
                                disabled={
                                    !can_edit ||
                                    action.busy ||
                                    contracts.length === 0 ||
                                    needs.length >= 200
                                }
                                onClick={() => edit(null)}
                            >
                                Добавить потребность
                            </Button>
                            <Button
                                variant="secondary"
                                disabled={
                                    !can_edit || action.busy || selected.length < 2
                                }
                                onClick={compare}
                            >
                                Сравнить выбранные ({selected.length})
                            </Button>
                        </div>
                        {contracts.length === 0 ? (
                            <GlassCard className="proposal-card">
                                <h2>Сначала отметьте победу в заявке</h2>
                                <p>
                                    Потребности привязываются к вашим контрактам на
                                    этапе «Победа».
                                </p>
                                <Link href={url('/participation')}>
                                    Перейти к участию →
                                </Link>
                            </GlassCard>
                        ) : null}
                        {moreContracts ? (
                            <InlineAlert title="Показаны 500 последних выигранных заявок">
                                Для добавления потребности доступны последние контракты.
                                Уже сохранённые потребности остаются видны.
                            </InlineAlert>
                        ) : null}
                        {candidates.length ? (
                            <GlassCard className="proposal-card">
                                <h2>Похожие потребности</h2>
                                <p>
                                    Совпало название и единица измерения. Характеристики
                                    и сроки проверьте вручную; это подсказка для
                                    сравнения.
                                </p>
                                {candidates.map((candidate, i) => (
                                    <div key={i} className="procurement-candidate">
                                        <strong>
                                            {candidate.title} ·{' '}
                                            {procurementQuantity(candidate.quantity)}{' '}
                                            {candidate.unit}
                                        </strong>
                                        {candidate.different_specs ? (
                                            <p>Есть различия в характеристиках.</p>
                                        ) : null}
                                        {candidate.different_dates ? (
                                            <p>Сроки потребностей различаются.</p>
                                        ) : null}
                                        <Button
                                            variant="secondary"
                                            disabled={!can_edit || action.busy}
                                            onClick={() => choose(candidate.need_ids)}
                                        >
                                            Выбрать для сравнения
                                        </Button>
                                    </div>
                                ))}
                            </GlassCard>
                        ) : null}
                        <div className="procurement-intro">
                            <h2>Потребности контрактов</h2>
                            <p>
                                Выберите 2–20 позиций, по одной на контракт, с
                                одинаковой единицей измерения. Для точного сравнения
                                нужны характеристики и срок каждой позиции. Здесь до 200
                                активных потребностей.
                            </p>
                        </div>
                        {needs.length === 0 && contracts.length > 0 ? (
                            <GlassCard className="proposal-card">
                                <h2>Что нужно купить для исполнения?</h2>
                                <p>
                                    Добавьте первую позицию: название, количество,
                                    характеристики и срок. Затем добавьте похожую
                                    потребность другого контракта.
                                </p>
                            </GlassCard>
                        ) : null}
                        {needs.map((need) => (
                            <GlassCard className="proposal-card" key={need.id}>
                                <label className="procurement-select">
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(need.id)}
                                        disabled={
                                            !can_edit ||
                                            action.busy ||
                                            need.confirmed_comparison_id !== null ||
                                            (!selected.includes(need.id) &&
                                                selected.length >= 20)
                                        }
                                        onChange={(event) =>
                                            setSelected(
                                                event.target.checked
                                                    ? [...selected, need.id]
                                                    : selected.filter(
                                                          (id) => id !== need.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <span>
                                        <strong>{need.title}</strong>
                                        <small>
                                            {procurementQuantity(need.quantity)}{' '}
                                            {need.unit} ·{' '}
                                            {procurementDate(need.required_on)}
                                        </small>
                                    </span>
                                </label>
                                <Link href={url(`/tenders/${need.tender_id}/work`)}>
                                    {need.contract} →
                                </Link>
                                <p className="procurement-spec">
                                    {need.specification ||
                                        'Характеристики не указаны: уточните перед сравнением.'}
                                </p>
                                {need.confirmed_comparison_id ? (
                                    <Link
                                        href={url(
                                            `/procurement/comparisons/${need.confirmed_comparison_id}`,
                                        )}
                                    >
                                        Уже распределено · открыть закупку →
                                    </Link>
                                ) : can_edit ? (
                                    <div className="proposal-actions">
                                        <Button
                                            variant="secondary"
                                            disabled={action.busy}
                                            onClick={() => edit(need)}
                                        >
                                            Изменить
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            disabled={action.busy}
                                            onClick={() => setDeleting(need)}
                                        >
                                            Удалить
                                        </Button>
                                    </div>
                                ) : (
                                    <Badge>Только просмотр</Badge>
                                )}
                            </GlassCard>
                        ))}
                        {selected.length ? (
                            <div className="proposal-actions">
                                <Button
                                    disabled={
                                        !can_edit || action.busy || selected.length < 2
                                    }
                                    onClick={compare}
                                >
                                    Сравнить выбранные ({selected.length})
                                </Button>
                                <Button
                                    variant="ghost"
                                    disabled={action.busy}
                                    onClick={() => setSelected([])}
                                >
                                    Снять выбор
                                </Button>
                            </div>
                        ) : null}
                        <GlassCard className="proposal-card">
                            <h2>Сохранённые сравнения</h2>
                            {comparisons.data.length === 0 ? (
                                <p>
                                    Здесь появятся черновики и подтверждённые расчёты.
                                </p>
                            ) : (
                                comparisons.data.map((c) => (
                                    <Link
                                        className="procurement-history"
                                        key={c.id}
                                        href={url(`/procurement/comparisons/${c.id}`)}
                                    >
                                        <strong>{c.title}</strong>
                                        <span>{procurementStatus[c.status]} →</span>
                                    </Link>
                                ))
                            )}
                            <div className="proposal-actions">
                                {comparisons.prev_page_url ? (
                                    <Link href={comparisons.prev_page_url}>
                                        ← Новее
                                    </Link>
                                ) : null}
                                {comparisons.next_page_url ? (
                                    <Link href={comparisons.next_page_url}>
                                        Ранее →
                                    </Link>
                                ) : null}
                            </div>
                        </GlassCard>
                    </>
                )}
                <BottomSheet
                    open={editing !== undefined}
                    onClose={() => {
                        if (!action.busy) setEditing(undefined);
                    }}
                    title={editing ? 'Изменить потребность' : 'Новая потребность'}
                >
                    <form
                        className="proposal-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <label>
                            Контракт
                            <select
                                aria-label="Контракт"
                                value={contract}
                                disabled={!!editing || action.busy}
                                required
                                onChange={(event) =>
                                    setContract(Number(event.target.value))
                                }
                            >
                                {editing &&
                                !contracts.some((c) => c.id === contract) ? (
                                    <option value={contract}>{editing.contract}</option>
                                ) : null}
                                {contracts.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Название товара или услуги
                            <input
                                required
                                maxLength={160}
                                value={title}
                                disabled={action.busy}
                                onChange={(event) => setTitle(event.target.value)}
                            />
                        </label>
                        <label>
                            Характеристики и комплектация
                            <textarea
                                rows={3}
                                maxLength={2000}
                                value={specification}
                                disabled={action.busy}
                                onChange={(event) =>
                                    setSpecification(event.target.value)
                                }
                                placeholder="Модель, размер, материал, состав услуги"
                            />
                        </label>
                        <div className="procurement-grid">
                            <label>
                                Количество
                                <input
                                    inputMode="decimal"
                                    required
                                    maxLength={9}
                                    value={quantity}
                                    disabled={action.busy}
                                    onChange={(event) =>
                                        setQuantity(event.target.value)
                                    }
                                    placeholder="Например, 40"
                                />
                            </label>
                            <label>
                                Единица измерения
                                <select
                                    aria-label="Единица измерения"
                                    value={unit}
                                    disabled={action.busy}
                                    onChange={(event) => setUnit(event.target.value)}
                                >
                                    {units.map((value) => (
                                        <option key={value}>{value}</option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <label>
                            Нужно к дате
                            <input
                                type="date"
                                value={requiredOn}
                                disabled={action.busy}
                                onChange={(event) => setRequiredOn(event.target.value)}
                            />
                        </label>
                        <p>
                            Можно сохранить неполные сведения и уточнить позже. Они не
                            дадут подтверждённую экономию, пока расчёт не будет полным.
                        </p>
                        {action.error ? (
                            <InlineAlert title="Проверьте данные" tone="danger">
                                {action.error}
                            </InlineAlert>
                        ) : null}
                        <Button type="submit" disabled={action.busy || !can_edit}>
                            {action.busy ? 'Сохраняем…' : 'Сохранить потребность'}
                        </Button>
                    </form>
                </BottomSheet>
                <BottomSheet
                    open={!!deleting}
                    onClose={() => {
                        if (!action.busy) setDeleting(null);
                    }}
                    title="Удалить потребность?"
                >
                    <div className="proposal-form">
                        <p>
                            {deleting?.title}. Сохранённые сравнения останутся в
                            истории, но эту позицию больше нельзя будет подтвердить в
                            них.
                        </p>
                        {action.error ? (
                            <InlineAlert title="Не удалось удалить" tone="danger">
                                {action.error}
                            </InlineAlert>
                        ) : null}
                        <Button disabled={action.busy} onClick={remove}>
                            Удалить потребность
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={action.busy}
                            onClick={() => setDeleting(null)}
                        >
                            Оставить
                        </Button>
                    </div>
                </BottomSheet>
            </AppShell>
        </>
    );
}
