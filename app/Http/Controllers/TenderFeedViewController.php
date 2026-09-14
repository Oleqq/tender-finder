<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TenderFeedView;
use App\Services\TeamWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenderFeedViewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $teamId = $request->integer('team_id') ?: null;
        $team = $teamId ? Team::query()->findOrFail($teamId) : null;
        if ($team) {
            app(TeamWorkspaceService::class)->authorize($user, $team, true);
        }
        $views = TenderFeedView::query()->where('user_id', $user->id)->where('team_id', $team?->id);
        if ($views->count() >= 10) {
            throw ValidationException::withMessages([
                'name' => 'Можно сохранить не более 10 представлений.',
            ]);
        }

        $request->merge(['name' => trim((string) $request->input('name'))]);

        $attributes = $request->validate([
            'team_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:60', Rule::unique('tender_feed_views')->where('user_id', $user->id)],
            'filters' => ['required', 'array'],
            'filters.q' => ['nullable', 'string', 'max:120'],
            'filters.status' => ['nullable', Rule::in($team
                ? ['all', 'new', 'reviewing', 'qualified', 'deferred', 'rejected']
                : ['all', 'new', 'favorite', 'potential', 'dismissed', 'archived'])],
            'filters.tag' => ['nullable', 'string', 'max:40'],
            'filters.query_id' => ['nullable', 'integer'],
            'filters.sort' => ['nullable', Rule::in(['matched_desc', 'deadline_asc', 'budget_desc', 'budget_asc'])],
            'filters.assignee_id' => ['nullable', 'integer'],
            'filters.source' => ['nullable', Rule::in(['all', 'eis_rss', 'rostender'])],
            'filters.overdue' => ['nullable', 'boolean'],
        ]);

        $filters = $this->filters($attributes['filters']);
        if (isset($filters['query_id'])) {
            abort_unless($team
                ? DB::table('team_search_queries')->where('team_id', $team->id)->where('search_query_id', $filters['query_id'])->exists()
                : $user->searchQueries()->whereKey($filters['query_id'])->exists(), 422);
        }

        $view = $user->tenderFeedViews()->create([
            'team_id' => $team?->id,
            'name' => $attributes['name'],
            'filters' => $filters,
        ]);

        return response()->json(['view' => [...$view->toArray(), 'can_delete' => true]], 201);
    }

    public function destroy(Request $request, TenderFeedView $view): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        if ($view->team_id) {
            $team = Team::query()->findOrFail($view->team_id);
            app(TeamWorkspaceService::class)->authorize($user, $team, true);
            abort_unless($view->user_id === $user->id || $team->owner_id === $user->id, 403);
        } else {
            abort_unless($view->user_id === $user->id, 404);
        }
        $view->delete();

        return response()->json([], 204);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, string|int|bool>
     */
    private function filters(array $filters): array
    {
        return collect($filters)
            ->only(['q', 'status', 'tag', 'query_id', 'assignee_id', 'source', 'sort', 'overdue'])
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();
    }
}
