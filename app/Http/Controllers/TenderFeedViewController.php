<?php

namespace App\Http\Controllers;

use App\Models\TenderFeedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenderFeedViewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if ($user->tenderFeedViews()->count() >= 10) {
            throw ValidationException::withMessages([
                'name' => 'Можно сохранить не более 10 представлений.',
            ]);
        }

        $request->merge(['name' => trim((string) $request->input('name'))]);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('tender_feed_views')->where('user_id', $user->id)],
            'filters' => ['required', 'array'],
            'filters.q' => ['nullable', 'string', 'max:120'],
            'filters.status' => ['nullable', Rule::in(['all', 'new', 'favorite', 'potential', 'dismissed', 'archived'])],
            'filters.tag' => ['nullable', 'string', 'max:40'],
            'filters.query_id' => ['nullable', 'integer'],
            'filters.sort' => ['nullable', Rule::in(['matched_desc', 'deadline_asc', 'budget_desc', 'budget_asc'])],
        ]);

        $filters = $this->filters($attributes['filters']);
        if (isset($filters['query_id'])) {
            abort_unless($user->searchQueries()->whereKey($filters['query_id'])->exists(), 422);
        }

        $view = $user->tenderFeedViews()->create([
            'name' => $attributes['name'],
            'filters' => $filters,
        ]);

        return response()->json(['view' => $view], 201);
    }

    public function destroy(Request $request, TenderFeedView $view): JsonResponse
    {
        abort_unless($view->user_id === $request->user()?->id, 404);
        $view->delete();

        return response()->json([], 204);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, string|int>
     */
    private function filters(array $filters): array
    {
        return collect($filters)
            ->only(['q', 'status', 'tag', 'query_id', 'sort'])
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();
    }
}
