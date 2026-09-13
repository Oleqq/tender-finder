import { scopedUrl } from '../lib/workspace';
import { Head, Link, usePage } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { TenderWorkNav } from '../Components/TenderWorkNav';
import { Badge, GlassCard } from '../Components/ui';
import { stages, stageLabel, type Stage } from '../lib/participation';
import { WorkspacePicker, type TeamScope } from '../Components/WorkspacePicker';
import type { PageProps } from '../types';

type Row = {
    id: number;
    tender_id: number;
    assignee_id: number | null;
    title: string;
    stage: Stage;
    loss_reason: string | null;
    deadline_at: string | null;
    items_count: number;
    completed_count: number;
};

export default function ParticipationPage() {
    const { participations, stage, counts, team, members } = usePage<
        PageProps<
            TeamScope & {
                participations: {
                    data: Row[];
                    total: number;
                    current_page: number;
                    last_page: number;
                    prev_page_url: string | null;
                    next_page_url: string | null;
                };
                stage: Stage | null;
                counts: Record<string, number>;
            }
        >
    >().props;
    return (
        <>
            <Head title="Участие в тендерах" />
            <AppShell
                title="Участие"
                eyebrow="От отбора до результата"
                className="work-page"
                activeNav="/tenders"
            >
                <TenderWorkNav active="/participation" />
                <WorkspacePicker path="/participation" />
                <div className="work-intro">
                    <h2>Ваши заявки</h2>
                    <p>
                        Добавляйте закупки из ленты, готовьте документы и фиксируйте
                        результат.
                    </p>
                </div>
                <nav className="work-stages" aria-label="Этап участия">
                    <Link
                        className={!stage ? 'is-active' : ''}
                        aria-current={!stage ? 'page' : undefined}
                        href={scopedUrl('/participation', team)}
                    >
                        Все{' '}
                        <strong>
                            {Object.values(counts).reduce(
                                (sum, n) => sum + Number(n),
                                0,
                            )}
                        </strong>
                    </Link>
                    {stages.map((s) => (
                        <Link
                            key={s.value}
                            className={stage === s.value ? 'is-active' : ''}
                            aria-current={stage === s.value ? 'page' : undefined}
                            href={scopedUrl(`/participation?stage=${s.value}`, team)}
                        >
                            {s.label} <strong>{counts[s.value] ?? 0}</strong>
                        </Link>
                    ))}
                </nav>
                {participations.data.length === 0 ? (
                    <GlassCard className="work-card">
                        <h2>Заявок пока нет</h2>
                        <p>
                            {stage
                                ? 'На этом этапе нет закупок.'
                                : 'Откройте закупку в ленте и нажмите «Участие и задачи».'}
                        </p>
                        <Link className="button button--secondary" href="/tenders">
                            Перейти к тендерам
                        </Link>
                    </GlassCard>
                ) : null}
                <div className="work-list">
                    {participations.data.map((row) => (
                        <GlassCard key={row.id} as="article" className="work-card">
                            {team && (
                                <p>
                                    Ответственный:{' '}
                                    {members.find((m) => m.id === row.assignee_id)
                                        ?.name ?? 'Не назначен'}
                                </p>
                            )}
                            <Badge
                                tone={
                                    row.stage === 'won'
                                        ? 'success'
                                        : row.stage === 'lost'
                                          ? 'danger'
                                          : 'accent'
                                }
                            >
                                {stageLabel(row.stage)}
                            </Badge>
                            <h2>
                                <Link
                                    href={scopedUrl(
                                        `/tenders/${row.tender_id}/work`,
                                        team,
                                    )}
                                >
                                    {row.title}
                                </Link>
                            </h2>
                            <p>
                                {row.deadline_at
                                    ? `Подача до ${new Date(row.deadline_at).toLocaleString('ru-RU')}`
                                    : 'Срок подачи не указан в источнике'}
                            </p>
                            <div className="work-progress">
                                <progress
                                    max={Math.max(row.items_count, 1)}
                                    value={row.completed_count}
                                    aria-label="Готовность чек-листа"
                                />
                                <span>
                                    {row.completed_count} / {row.items_count} задач
                                </span>
                            </div>
                            {row.loss_reason ? (
                                <p className="work-reason">
                                    Причина проигрыша: {row.loss_reason}
                                </p>
                            ) : null}
                            <Link
                                href={scopedUrl(`/tenders/${row.tender_id}/work`, team)}
                                className="button button--secondary"
                            >
                                Открыть заявку
                            </Link>
                        </GlassCard>
                    ))}
                </div>
                {participations.last_page > 1 ? (
                    <nav className="work-toolbar" aria-label="Страницы заявок">
                        {participations.prev_page_url ? (
                            <Link
                                href={participations.prev_page_url}
                                className="button button--secondary"
                            >
                                Назад
                            </Link>
                        ) : (
                            <span />
                        )}
                        <span>
                            {participations.current_page} / {participations.last_page}
                        </span>
                        {participations.next_page_url ? (
                            <Link
                                href={participations.next_page_url}
                                className="button button--secondary"
                            >
                                Далее
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                ) : null}
            </AppShell>
        </>
    );
}
