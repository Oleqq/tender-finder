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
}
