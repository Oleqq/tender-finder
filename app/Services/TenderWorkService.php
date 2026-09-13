<?php

namespace App\Services;

use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderChecklistItem;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TenderWorkService
{
    /** @return Builder<Tender> */
    public function accessible(User $user): Builder
    {
        $ids = app(LocalMvpOperatorService::class)->canUseWorkspace($user)
            ? app(LocalMvpSearchSnapshotService::class)->accessibleTenderIdsFor($user) : [];

        return Tender::query()->where(function (Builder $query) use ($user, $ids): void {
            $query->whereHas('matches.searchQuery', fn (Builder $q) => $q->where('user_id', $user->id));
            if ($ids !== []) {
                $query->orWhere(fn (Builder $q) => $q->whereIn('id', $ids)->whereIn('source', ['eis_rss', 'tenderguru_preview']));
            }
        });
    }

    /** @return Builder<TenderParticipation> */
    public function participations(User $user, ?Team $team): Builder
    {
        if ($team) {
            app(TeamWorkspaceService::class)->authorize($user, $team);
        }

        return TenderParticipation::query()->when($team,
            fn (Builder $q) => $q->where('team_id', $team->id),
            fn (Builder $q) => $q->whereNull('team_id')->where('user_id', $user->id));
    }

    public function authorize(User $user, Tender $tender, ?Team $team = null): void
    {
        if ($team && $this->participations($user, $team)->where('tender_id', $tender->id)->exists()) {
            return;
        }
        abort_unless($this->accessible($user)->whereKey($tender->id)->exists(), 404);
    }

    /** @return array<string, mixed>|null */
    public function present(?TenderParticipation $participation): ?array
    {
        if ($participation === null) {
            return null;
        }

        return [
            'stage' => $participation->stage->value,
            'assignee_id' => $participation->assignee_id,
            'loss_reason' => $participation->loss_reason,
            'version' => $participation->version,
            'economics' => $this->economics($participation),
            'items' => $participation->items()->orderBy('id')->get()->map(fn (TenderChecklistItem $item): array => [
                'id' => $item->id, 'title' => $item->title, 'due_on' => $item->due_on?->format('Y-m-d'),
                'assignee_id' => $item->assignee_id, 'reminder_enabled' => $item->reminder_enabled,
                'completed' => $item->completed_at !== null, 'version' => $item->version,
            ])->all(),
            'history' => DB::table('tender_participation_events')->where('participation_id', $participation->id)
                ->orderByDesc('id')->get(['id', 'from_stage', 'to_stage', 'reason', 'created_at'])->map(fn ($event): array => [
                    'id' => $event->id, 'from_stage' => $event->from_stage, 'to_stage' => $event->to_stage,
                    'reason' => $event->reason, 'created_at' => Carbon::parse($event->created_at)->toAtomString(),
                ])->all(),
        ];
    }

    /** @return array<string, float|int|string|null> */
    public function economics(TenderParticipation $participation): array
    {
        $plannedRevenue = $this->money($participation->planned_revenue);
        $plannedExpenses = $this->money($participation->planned_cost) + $this->money($participation->security_cost)
            + $this->money($participation->commission_cost) + $this->money($participation->other_cost);
        $plannedMargin = $plannedRevenue - $plannedExpenses;
        $actualRevenue = $this->money($participation->actual_revenue);
        $actualCost = $this->money($participation->actual_cost);

        return [
            'planned_revenue' => $participation->planned_revenue,
            'planned_cost' => $participation->planned_cost,
            'security_cost' => $participation->security_cost,
            'commission_cost' => $participation->commission_cost,
            'other_cost' => $participation->other_cost,
            'actual_revenue' => $participation->actual_revenue,
            'actual_cost' => $participation->actual_cost,
            'decision' => $participation->participation_decision,
            'decision_note' => $participation->decision_note,
            'version' => $participation->economics_version,
            'planned_expenses' => round($plannedExpenses, 2),
            'planned_margin' => round($plannedMargin, 2),
            'planned_margin_percent' => $plannedRevenue > 0 ? round($plannedMargin / $plannedRevenue * 100, 1) : null,
            'actual_margin' => round($actualRevenue - $actualCost, 2),
        ];
    }

    private function money(mixed $value): float
    {
        return $value === null ? 0.0 : (float) $value;
    }
}
