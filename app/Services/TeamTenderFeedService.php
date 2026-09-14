<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\TeamTenderReview;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TeamTenderFeedService
{
    public function assertTenderIsShared(Team $team, Tender $tender): void
    {
        $exists = DB::table('tender_query_matches')
            ->join('team_search_queries', 'team_search_queries.search_query_id', '=', 'tender_query_matches.search_query_id')
            ->where('team_search_queries.team_id', $team->id)
            ->where('tender_query_matches.tender_id', $tender->id)
            ->exists();

        abort_unless($exists, 404);
    }

    public function share(User $user, Team $team, SearchQuery $query): void
    {
        DB::transaction(function () use ($user, $team, $query): void {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            app(TeamWorkspaceService::class)->authorize($user, $team, true);
            abort_unless($query->user_id === $user->id && $query->status->value !== 'deleted', 404);
            abort_if(
                DB::table('team_search_queries')->where('team_id', $team->id)->count() >= 50
                && ! DB::table('team_search_queries')->where('team_id', $team->id)->where('search_query_id', $query->id)->exists(),
                422,
                'К командной ленте можно подключить до 50 мониторингов.',
            );

            DB::table('team_search_queries')->insertOrIgnore([
                'team_id' => $team->id,
                'search_query_id' => $query->id,
                'shared_by_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        app(TeamWorkflowService::class)->sync($team);
    }

    public function unshare(User $user, Team $team, SearchQuery $query): void
    {
        app(TeamWorkspaceService::class)->authorize($user, $team, true);
        $shared = DB::table('team_search_queries')
            ->where('team_id', $team->id)
            ->where('search_query_id', $query->id);
        $row = $shared->first();
        abort_unless($row !== null, 404);
        abort_unless($team->owner_id === $user->id || (int) $row->shared_by_id === $user->id, 403);
        $shared->delete();
    }

    public function review(Team $team, Tender $tender): TeamTenderReview
    {
        return DB::transaction(function () use ($team, $tender): TeamTenderReview {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();

            return TeamTenderReview::query()->firstOrCreate(
                ['team_id' => $team->id, 'tender_id' => $tender->id],
                ['status' => 'new', 'version' => 1],
            );
        });
    }

    /** @param array{status:string,assignee_id:?int,rejection_reason:?string,version:int} $data */
    public function updateReview(User $actor, Team $team, Tender $tender, array $data): TeamTenderReview
    {
        return DB::transaction(function () use ($actor, $team, $tender, $data): TeamTenderReview {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            app(TeamWorkspaceService::class)->authorize($actor, $team, true);
            $this->assertTenderIsShared($team, $tender);
            $review = TeamTenderReview::query()->where('team_id', $team->id)->where('tender_id', $tender->id)->lockForUpdate()->first();
            $version = $review === null ? 0 : $review->version;
            abort_if($version !== $data['version'], 409, 'Карточка изменена другим участником. Обновите страницу.');
            $review ??= new TeamTenderReview(['team_id' => $team->id, 'tender_id' => $tender->id, 'version' => 0,
                'due_at' => now()->addHours(app(TeamWorkflowService::class)->settings($team)->review_sla_hours)]);
            $assignee = app(TeamWorkspaceService::class)->assignee($actor, $team, $data['assignee_id']);
            $assigneeChanged = $review->assignee_id !== $assignee;
            $review->fill([
                'status' => $data['status'], 'assignee_id' => $assignee, 'reviewed_by_id' => $actor->id,
                'rejection_reason' => $data['status'] === 'rejected' ? trim((string) $data['rejection_reason']) : null,
                'assigned_at' => $assigneeChanged && $assignee ? now() : $review->assigned_at,
                'sla_alerted_at' => $assigneeChanged ? null : $review->sla_alerted_at,
                'version' => $version + 1,
            ])->save();
            app(TeamActivityService::class)->record($team, $actor, 'tender_reviewed', [
                'tender_id' => $tender->id, 'status' => $review->status, 'assignee_id' => $assignee,
            ]);
            if ($assigneeChanged && $assignee) {
                app(TeamWorkflowService::class)->notifyAssignment($review);
            }

            return $review;
        });
    }
}
