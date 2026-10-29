import '../../css/proposals.css';
import '../../css/winner-opportunities.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import { WinnerEvidenceForm } from '../Components/WinnerEvidenceForm';
import { winnerOutcomes, type WinnerPage } from '../lib/winnerOpportunities';
import type { PageProps } from '../types';

type Props = {
    enabled: boolean;
    archived: boolean;
    opportunities: WinnerPage<{
        id: number;
        title: string;
        winner_name: string;
        checked_on: string;
        outcome: string;
    }> | null;
};
export default function WinnerOpportunities() {
    const { enabled, archived, opportunities } = usePage<PageProps<Props>>().props;
    const [creating, setCreating] = useState(false);
    return (
        <>
            <Head title="Поставки победителю" />
            <AppShell
                title="Поставки победителю"
                eyebrow="Личное · Ultra"
                backHref="/profile"
                className="feature-page winner-page"
                activeNav="/profile"
            >
                <section className="proposal-intro">
                    <h2>Предложите часть поставки исполнителю контракта</h2>
                    <p>
                        Сохраните проверенные итоги закупки, найдите подходящие позиции
                        своего прайса и подготовьте обращение победителю.
                    </p>
                    <p>
                        Победа не означает, что исполнителю нужен подрядчик. Интерес к
                        вашей поставке можно выяснить только в разговоре. Подбор и
                        история личные; коллеги их не видят.
                    </p>
                </section>
                {!enabled ? (
                    <InlineAlert title="Доступно при действующем Ultra">
                        <Link href="/plans">Мой доступ</Link>
                    </InlineAlert>
                ) : (
                    <>
                        <div className="proposal-actions">
                            <Button onClick={() => setCreating(true)}>
                                Добавить победителя
                            </Button>
                            <Link
                                href={
                                    archived
                                        ? '/winner-opportunities'
                                        : '/winner-opportunities?archived=1'
                                }
                            >
                                {archived ? 'Активные возможности' : 'Архив'}
                            </Link>
                            <Link href="/catalog">Мой прайс</Link>
                        </div>
                        <p className="winner-hint">
                            {archived
                                ? 'Архив: история сохранена, редактирование после восстановления.'
                                : 'До 100 активных возможностей. Автоматический сбор победителей пока не подключён.'}
                        </p>
                        {!opportunities?.data.length ? (
                            <GlassCard className="proposal-card">
                                <h2>
                                    {archived
                                        ? 'Архив пока пуст'
                                        : 'Начните с одного подтверждённого победителя'}
                                </h2>
                                <p>
                                    Нужны ссылка на итоги, цитата с названием
                                    победителя, даты и описание подходящей вам части
                                    закупки. Без этих данных запись не создаётся.
                                </p>
                            </GlassCard>
                        ) : null}
                        {opportunities?.data.map((row) => (
                            <GlassCard key={row.id} className="proposal-card">
                                <Badge>{winnerOutcomes[row.outcome]}</Badge>
                                <h2>
                                    <Link href={`/winner-opportunities/${row.id}`}>
                                        {row.title}
                                    </Link>
                                </h2>
                                <p>{row.winner_name}</p>
                                <p>Проверено вами: {row.checked_on}</p>
                                <Link href={`/winner-opportunities/${row.id}`}>
                                    Открыть подбор и обращение
                                </Link>
                            </GlassCard>
                        ))}
                        <div className="proposal-actions">
                            {opportunities?.prev_page_url ? (
                                <Link href={opportunities.prev_page_url}>
                                    Предыдущие
                                </Link>
                            ) : null}
                            {opportunities?.next_page_url ? (
                                <Link href={opportunities.next_page_url}>
                                    Следующие
                                </Link>
                            ) : null}
                        </div>
                    </>
                )}
                <BottomSheet
                    open={creating}
                    title="Подтверждённый победитель"
                    onClose={() => setCreating(false)}
                >
                    {creating ? (
                        <WinnerEvidenceForm onCancel={() => setCreating(false)} />
                    ) : null}
                </BottomSheet>
            </AppShell>
        </>
    );
}
