<?php

namespace App\Services;

use App\Enums\ParticipationStage;
use App\Models\Team;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ParticipationAnalyticsService
{
    /** @return array<string, mixed> */
    public function snapshot(User $user, ?Team $team, string $period): array
    {
        $from = $period === 'all' ? null : now()->subDays((int) $period)->startOfDay();
        $rows = $this->base($user, $team, $from)->with('tender')->get();
        $completed = $rows->filter(fn (TenderParticipation $row): bool => in_array($row->stage, [ParticipationStage::Won, ParticipationStage::Lost], true));
        $won = $rows->where('stage', ParticipationStage::Won);
        $active = $rows->filter(fn (TenderParticipation $row): bool => ! in_array($row->stage, [ParticipationStage::Won, ParticipationStage::Lost], true));
        $events = DB::table('tender_participation_events')->whereIn('participation_id', $rows->pluck('id'))
            ->whereIn('to_stage', ['won', 'lost'])->orderBy('created_at')->get()->groupBy('participation_id');
        $durations = $completed->map(function (TenderParticipation $row) use ($events): ?int {
            $finished = ($events[$row->id] ?? collect())->last();

            return $finished ? max(0, (int) $row->created_at->startOfDay()->diffInDays(Carbon::parse($finished->created_at)->startOfDay())) : null;
        })->filter(fn ($days): bool => $days !== null);
        $stages = collect(ParticipationStage::cases())->mapWithKeys(fn (ParticipationStage $stage): array => [
            $stage->value => ['label' => $stage->label(), 'count' => $rows->where('stage', $stage)->count()],
        ])->all();
        $lossReasons = $rows->where('stage', ParticipationStage::Lost)->filter(fn ($row): bool => filled($row->loss_reason))
            ->groupBy(fn ($row): string => mb_substr(trim((string) $row->loss_reason), 0, 120))
            ->map(fn (Collection $group, string $reason): array => ['reason' => $reason, 'count' => $group->count()])
            ->sortByDesc('count')->take(8)->values()->all();

        return [
            'period' => $period,
            'summary' => [
                'total' => $rows->count(), 'active' => $active->count(), 'won' => $won->count(), 'lost' => $rows->where('stage', ParticipationStage::Lost)->count(),
                'win_rate' => $completed->isEmpty() ? null : round($won->count() / $completed->count() * 100, 1),
                'pipeline_amount' => $this->sumBudgets($active), 'won_amount' => $this->sumBudgets($won),
                'average_cycle_days' => $durations->isEmpty() ? null : round((float) $durations->average(), 1),
                'planned_margin' => round($rows->sum(fn (TenderParticipation $row): float => $this->margin($row, false)), 2),
                'actual_margin' => round($rows->sum(fn (TenderParticipation $row): float => $this->margin($row, true)), 2),
            ],
            'stages' => $stages,
            'loss_reasons' => $lossReasons,
            'members' => $this->members($rows, $team),
            'series' => $this->series($rows),
        ];
    }

    public function csv(User $user, ?Team $team, string $period): string
    {
        $snapshot = $this->snapshot($user, $team, $period);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Показатель', 'Значение'], ';');
        $labels = ['total' => 'Всего заявок', 'active' => 'В работе', 'won' => 'Победы', 'lost' => 'Проигрыши', 'win_rate' => 'Процент побед',
            'pipeline_amount' => 'НМЦК в работе', 'won_amount' => 'НМЦК побед', 'average_cycle_days' => 'Средний цикл, дней',
            'planned_margin' => 'Плановая маржа', 'actual_margin' => 'Фактическая маржа'];
        foreach ($labels as $key => $label) {
            fputcsv($stream, [$label, $snapshot['summary'][$key] ?? ''], ';');
        }
        fputcsv($stream, [], ';');
        fputcsv($stream, ['Этап', 'Количество'], ';');
        foreach ($snapshot['stages'] as $stage) {
            fputcsv($stream, [$stage['label'], $stage['count']], ';');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF".$csv;
    }

    /** @return Builder<TenderParticipation> */
    private function base(User $user, ?Team $team, ?Carbon $from): Builder
    {
        return app(TenderWorkService::class)->participations($user, $team)
            ->when(! $team, fn (Builder $query) => $query->whereIn('tender_id', app(TenderWorkService::class)->accessible($user)->select('tenders.id')))
            ->when($from, fn (Builder $query) => $query->where('created_at', '>=', $from));
    }

    /** @param Collection<int, TenderParticipation> $rows */
    private function sumBudgets(Collection $rows): float
    {
        return round($rows->sum(fn (TenderParticipation $row): float => (float) ($row->tender->budget_amount ?? 0)), 2);
    }

    private function margin(TenderParticipation $row, bool $actual): float
    {
        if ($actual) {
            return (float) ($row->actual_revenue ?? 0) - (float) ($row->actual_cost ?? 0);
        }

        return (float) ($row->planned_revenue ?? 0) - (float) ($row->planned_cost ?? 0) - (float) ($row->security_cost ?? 0)
            - (float) ($row->commission_cost ?? 0) - (float) ($row->other_cost ?? 0);
    }

    /** @param Collection<int, TenderParticipation> $rows
     * @return array<int, array<string, mixed>>
     */
    private function members(Collection $rows, ?Team $team): array
    {
        if (! $team) {
            return [];
        }
        $names = DB::table('team_members')->join('users', 'users.id', '=', 'team_members.user_id')->where('team_id', $team->id)->pluck('users.name', 'users.id');

        return $names->map(function (string $name, int|string $id) use ($rows): array {
            $memberRows = $rows->where('assignee_id', (int) $id);
            $completed = $memberRows->filter(fn ($row): bool => in_array($row->stage, [ParticipationStage::Won, ParticipationStage::Lost], true));
            $wins = $memberRows->where('stage', ParticipationStage::Won)->count();

            return ['id' => (int) $id, 'name' => $name, 'total' => $memberRows->count(),
                'active' => $memberRows->filter(fn ($row): bool => ! in_array($row->stage, [ParticipationStage::Won, ParticipationStage::Lost], true))->count(),
                'won' => $wins, 'win_rate' => $completed->isEmpty() ? null : round($wins / $completed->count() * 100, 1)];
        })->sortByDesc('total')->values()->all();
    }

    /** @param Collection<int, TenderParticipation> $rows
     * @return array<int, array<string, mixed>>
     */
    private function series(Collection $rows): array
    {
        return $rows->groupBy(fn (TenderParticipation $row): string => $row->created_at->format('Y-m'))
            ->sortKeys()->map(fn (Collection $group, string $month): array => ['month' => $month, 'total' => $group->count(),
                'won' => $group->where('stage', ParticipationStage::Won)->count(), 'lost' => $group->where('stage', ParticipationStage::Lost)->count()])
            ->values()->all();
    }
}
