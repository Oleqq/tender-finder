<?php

namespace App\Http\Controllers;

use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\TeamTenderReview;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Services\TeamActivityService;
use App\Services\TeamTenderFeedService;
use App\Services\TeamWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TeamTenderFeedController extends Controller
{
    public function shareMonitoring(Request $request, Team $team, TeamTenderFeedService $feed): JsonResponse
    {
        $data = $request->validate(['search_query_id' => ['required', 'integer']]);
        $query = SearchQuery::query()->findOrFail($data['search_query_id']);
        $feed->share($request->user(), $team, $query);
        app(TeamActivityService::class)->record($team, $request->user(), 'monitoring_shared', ['search_query_id' => $query->id]);

        return response()->json(['shared' => true], 201);
    }

    public function unshareMonitoring(Request $request, Team $team, SearchQuery $query, TeamTenderFeedService $feed): JsonResponse
    {
        $feed->unshare($request->user(), $team, $query);
        app(TeamActivityService::class)->record($team, $request->user(), 'monitoring_unshared', ['search_query_id' => $query->id]);

        return response()->json(['shared' => false]);
    }

    public function update(Request $request, Team $team, Tender $tender, TeamTenderFeedService $feed): JsonResponse
    {
        app(TeamWorkspaceService::class)->authorize($request->user(), $team, true);
        $feed->assertTenderIsShared($team, $tender);
        $data = $request->validate([
            'status' => ['required', Rule::in(['new', 'reviewing', 'qualified', 'deferred', 'rejected'])],
            'assignee_id' => ['nullable', 'integer'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        $review = DB::transaction(function () use ($request, $team, $tender, $data): TeamTenderReview {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $review = TeamTenderReview::query()->where('team_id', $team->id)->where('tender_id', $tender->id)->lockForUpdate()->first();
            $version = $review === null ? 0 : $review->version;
            abort_if($version !== (int) $data['version'], 409, 'Карточка изменена другим участником. Обновите страницу.');
            $review ??= new TeamTenderReview(['team_id' => $team->id, 'tender_id' => $tender->id, 'version' => 0]);
            $assignee = app(TeamWorkspaceService::class)->assignee($request->user(), $team, $data['assignee_id'] ?? null);
            $review->fill([
                'status' => $data['status'],
                'assignee_id' => $assignee,
                'reviewed_by_id' => $request->user()->id,
                'rejection_reason' => $data['status'] === 'rejected' ? trim((string) $data['rejection_reason']) : null,
                'version' => $version + 1,
            ])->save();
            app(TeamActivityService::class)->record($team, $request->user(), 'tender_reviewed', [
                'tender_id' => $tender->id, 'status' => $review->status, 'assignee_id' => $assignee,
            ]);

            return $review;
        });

        return response()->json(['review' => $this->present($review)]);
    }

    public function comment(Request $request, Team $team, Tender $tender, TeamTenderFeedService $feed): JsonResponse
    {
        app(TeamWorkspaceService::class)->authorize($request->user(), $team, true);
        $feed->assertTenderIsShared($team, $tender);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $review = $feed->review($team, $tender);
        abort_if($review->comments()->count() >= 500, 422, 'В обсуждении допускается до 500 комментариев.');
        $comment = $review->comments()->create(['author_id' => $request->user()->id, 'body' => trim($data['body'])]);
        app(TeamActivityService::class)->record($team, $request->user(), 'tender_review_commented', ['tender_id' => $tender->id]);

        return response()->json(['comment' => [
            'id' => $comment->id,
            'author_id' => $request->user()->id,
            'author_name' => $request->user()->name,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toAtomString(),
        ]], 201);
    }

    public function promote(Request $request, Team $team, Tender $tender, TeamTenderFeedService $feed): JsonResponse
    {
        app(TeamWorkspaceService::class)->authorize($request->user(), $team, true);
        $feed->assertTenderIsShared($team, $tender);
        $data = $request->validate(['assignee_id' => ['nullable', 'integer']]);

        $participation = DB::transaction(function () use ($request, $team, $tender, $data): TenderParticipation {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $assignee = app(TeamWorkspaceService::class)->assignee($request->user(), $team, $data['assignee_id'] ?? null);
            $participation = TenderParticipation::query()->firstOrCreate(
                ['team_id' => $team->id, 'tender_id' => $tender->id],
                ['user_id' => $request->user()->id, 'assignee_id' => $assignee, 'stage' => 'studying', 'version' => 1],
            );
            if ($participation->wasRecentlyCreated) {
                DB::table('tender_participation_events')->insert([
                    'participation_id' => $participation->id, 'from_stage' => null, 'to_stage' => 'studying',
                    'actor_id' => $request->user()->id, 'reason' => null, 'created_at' => now(),
                ]);
            }
            $review = TeamTenderReview::query()->firstOrCreate(
                ['team_id' => $team->id, 'tender_id' => $tender->id],
                ['status' => 'qualified', 'assignee_id' => $assignee, 'reviewed_by_id' => $request->user()->id],
            );
            if (! $review->wasRecentlyCreated && $review->status !== 'qualified') {
                $review->update(['status' => 'qualified', 'assignee_id' => $assignee, 'reviewed_by_id' => $request->user()->id, 'rejection_reason' => null, 'version' => $review->version + 1]);
            }
            app(TeamActivityService::class)->record($team, $request->user(), 'tender_promoted', [
                'tender_id' => $tender->id, 'participation_id' => $participation->id, 'assignee_id' => $participation->assignee_id,
            ]);

            return $participation;
        });

        return response()->json(['participation_id' => $participation->id], $participation->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function present(TeamTenderReview $review): array
    {
        return [
            'status' => $review->status,
            'assignee_id' => $review->assignee_id,
            'rejection_reason' => $review->rejection_reason,
            'version' => $review->version,
        ];
    }
}
