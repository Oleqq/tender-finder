<?php

namespace App\Services;

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

    public function authorize(User $user, Tender $tender): void
    {
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
            'loss_reason' => $participation->loss_reason,
            'version' => $participation->version,
            'items' => $participation->items()->orderBy('id')->get()->map(fn (TenderChecklistItem $item): array => [
                'id' => $item->id, 'title' => $item->title, 'due_on' => $item->due_on?->format('Y-m-d'),
                'completed' => $item->completed_at !== null, 'version' => $item->version,
            ])->all(),
            'history' => DB::table('tender_participation_events')->where('participation_id', $participation->id)
                ->orderByDesc('id')->get(['id', 'from_stage', 'to_stage', 'reason', 'created_at'])->map(fn ($event): array => [
                    'id' => $event->id, 'from_stage' => $event->from_stage, 'to_stage' => $event->to_stage,
                    'reason' => $event->reason, 'created_at' => Carbon::parse($event->created_at)->toAtomString(),
                ])->all(),
        ];
    }
}
