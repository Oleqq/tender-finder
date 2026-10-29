import '../../css/proposals.css';
import '../../css/repeat-tenders.css';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import { RepeatTenderLookup } from '../Components/RepeatTenderLookup';
import { useProposalAction } from '../lib/proposals';
import {
    repeatDate,
    type RepeatSnapshot,
    type RepeatWatch,
    type RepeatPage,
} from '../lib/repeatTenders';
import type { PageProps } from '../types';

type Props = {
    enabled: boolean;
    onlyUnread: boolean;
    archived: boolean;
    unread: number;
    from: RepeatSnapshot | null;
    watches: RepeatPage<RepeatWatch> | null;
};
export default function RepeatTenders() {
    const { enabled, archived, unread, from, watches, onlyUnread } =
        usePage<PageProps<Props>>().props;
    const [creating, setCreating] = useState(!!from);
    const [selected, setSelected] = useState<RepeatSnapshot | null>(from);
    const [reason, setReason] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const action = useProposalAction();
    async function create() {
        const result = await action.run<{ id: number }>(
            'post',
            '/repeat-tenders',
            { tender_id: selected?.id, reason, confirmed },
            'Наблюдение сохранено.',
        );
        if (result) router.visit('/repeat-tenders/' + result.id);
    }
    return (
        <>
            <Head title="Повторные закупки" />
            <AppShell
                title="Повторные закупки"
                eyebrow="Личное · Ultra"
                backHref="/profile"
                className="repeat-page"
            >
                <section className="proposal-intro">
                    <h2>Вернитесь к закупке, когда условия изменятся</h2>
                    <p>
                        Сохраните интересную закупку и причину, по которой не
                        участвовали или не прошли. Мы проверим новые карточки ваших
                        мониторингов на возможный повтор.
                    </p>
                    <p>
                        Подсказка по названию и заказчику — гипотеза. Для подтверждения
                        связи нужны источник и ваша проверка. Сравнение относится к
                        полям карточек, полную документацию сверяйте отдельно.
                    </p>
                </section>
                {!enabled ? (
                    <InlineAlert title="Доступно при действующем Ultra">
                        <Link href="/plans">Мой доступ</Link>
                    </InlineAlert>
                ) : (
                    <>
                        {unread > 0 ? (
                            <InlineAlert title={`Новых уведомлений: ${unread}`}>
                                Откройте наблюдения и проверьте найденные объявления.
                                Уведомления доступны внутри приложения.
                            </InlineAlert>
                        ) : null}
                        <div className="proposal-actions">
                            <Button
                                onClick={() => {
                                    setCreating(true);
                                    setConfirmed(false);
                                }}
                            >
                                Добавить наблюдение
                            </Button>
                            <Link
                                href={
                                    archived
                                        ? '/repeat-tenders'
                                        : '/repeat-tenders?archived=1'
                                }
                            >
                                {archived ? 'Активные наблюдения' : 'Архив'}
                            </Link>
                        </div>
                        {unread > 0 || onlyUnread ? (
                            <Link
                                href={
                                    onlyUnread
                                        ? '/repeat-tenders'
                                        : '/repeat-tenders?unread=1'
                                }
                            >
                                {onlyUnread
                                    ? 'Показать все наблюдения'
                                    : 'Только с новыми уведомлениями'}
                            </Link>
                        ) : null}
                        {!watches?.data.length ? (
                            <GlassCard className="proposal-card">
                                <h2>
                                    {archived
                                        ? 'Архив пока пуст'
                                        : 'Какая закупка могла бы подойти при других условиях?'}
                                </h2>
                                <p>
                                    Например, вы отказались из-за короткого срока
                                    поставки. Сохраните это, чтобы сравнить с новым
                                    объявлением.
                                </p>
                            </GlassCard>
                        ) : null}
                        {watches?.data.map((watch) => (
                            <GlassCard key={watch.id} className="proposal-card">
                                {watch.unread > 0 ? (
                                    <p>Новых уведомлений: {watch.unread}</p>
                                ) : null}
                                <h2>
                                    <Link href={'/repeat-tenders/' + watch.id}>
                                        {watch.original.title}
                                    </Link>
                                </h2>
                                <p className="proposal-text">
                                    Причина отказа: {watch.reason}
                                </p>
                                <p>
                                    Последняя проверка: {repeatDate(watch.checked_at)}
                                </p>
                                {!watch.available ? (
                                    <p>
                                        Исходная закупка недоступна; сохранённый снимок
                                        и история остались.
                                    </p>
                                ) : null}
                                <Link href={'/repeat-tenders/' + watch.id}>
                                    Посмотреть совпадения и изменения
                                </Link>
                            </GlassCard>
                        ))}
                        <div className="proposal-actions">
                            {watches?.prev_page_url ? (
                                <Link href={watches.prev_page_url}>Предыдущие</Link>
                            ) : null}
                            {watches?.next_page_url ? (
                                <Link href={watches.next_page_url}>Следующие</Link>
                            ) : null}
                        </div>
                    </>
                )}
                <BottomSheet
                    open={enabled && creating}
                    title="Следить за повторной закупкой"
                    onClose={() => {
                        if (!action.busy) setCreating(false);
                    }}
                >
                    <div className="proposal-form">
                        <RepeatTenderLookup
                            disabled={action.busy}
                            onSelect={setSelected}
                        />
                        {selected ? <p>Выбрана: {selected.title}</p> : null}
                        <label>
                            Почему отказались или не прошли
                            <textarea
                                aria-label="Почему отказались или не прошли"
                                rows={4}
                                maxLength={3000}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                        </label>
                        <p>
                            Первоначальные поля карточки сохранятся снимком. Это не
                            меняет статус участия и не переносит расчёты в новую
                            закупку.
                        </p>
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={confirmed}
                                onChange={(e) => setConfirmed(e.target.checked)}
                            />
                            <span>
                                Сохраняю эту закупку и причину отказа для личного
                                наблюдения.
                            </span>
                        </label>
                        {action.error ? (
                            <InlineAlert title="Не удалось сохранить" tone="danger">
                                {action.error}
                            </InlineAlert>
                        ) : null}
                        <div className="proposal-actions">
                            <Button
                                disabled={
                                    action.busy ||
                                    !selected ||
                                    !confirmed ||
                                    !reason.trim()
                                }
                                onClick={() => void create()}
                            >
                                Сохранить наблюдение
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={action.busy}
                                onClick={() => setCreating(false)}
                            >
                                Отмена
                            </Button>
                        </div>
                    </div>
                </BottomSheet>
            </AppShell>
        </>
    );
}
