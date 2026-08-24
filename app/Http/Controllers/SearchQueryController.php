<?php

namespace App\Http\Controllers;

use App\Models\SearchQuery;
use App\Services\QueryAccessDeniedException;
use App\Services\QueryLimitReachedException;
use App\Services\SearchQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SearchQueryController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('MyQueries', [
            'queries' => $request->user()
                ->searchQueries()
                ->where('status', '!=', 'deleted')
                ->latest()
                ->get()
                ->map(fn (SearchQuery $query): array => $this->queryDto($query))
                ->values(),
        ]);
    }

    public function store(Request $request, SearchQueryService $queries): JsonResponse
    {
        try {
            $query = $queries->create($request->user(), $this->validatedAttributes($request));
        } catch (QueryAccessDeniedException) {
            throw ValidationException::withMessages([
                'access' => 'Создать мониторинг можно только с активным Basic-доступом или trial.',
            ]);
        } catch (QueryLimitReachedException) {
            throw ValidationException::withMessages([
                'limit' => 'Достигнут лимит: в закрытой бете доступны 3 активных мониторинга.',
            ]);
        }

        return response()->json(['query' => $this->queryDto($query)], 201);
    }

    public function update(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->queryDto($queries->update($query, $this->validatedAttributes($request)))]);
    }

    public function pause(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->queryDto($queries->pause($query))]);
    }

    public function resume(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        try {
            $query = $queries->resume($query);
        } catch (QueryAccessDeniedException) {
            throw ValidationException::withMessages([
                'access' => 'Возобновить мониторинг можно только с активным доступом.',
            ]);
        } catch (QueryLimitReachedException) {
            throw ValidationException::withMessages([
                'limit' => 'Сначала поставьте на паузу другой мониторинг: доступно только 3 активных.',
            ]);
        }

        return response()->json(['query' => $this->queryDto($query)]);
    }

    public function freeze(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->queryDto($queries->freeze($query))]);
    }

    public function destroy(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);
        $queries->delete($query);

        return response()->json(status: 204);
    }

    /** @return array<string, mixed> */
    private function validatedAttributes(Request $request): array
    {
        $attributes = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['required', 'string', 'max:100', 'distinct'],
            'minus_keywords' => ['nullable', 'array', 'max:20'],
            'minus_keywords.*' => ['required', 'string', 'max:100', 'distinct'],
            'region' => ['nullable', 'string', 'max:120'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'gte:budget_min'],
            'deadline_from' => ['nullable', 'date_format:Y-m-d'],
            'deadline_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:deadline_from'],
        ]);

        $keywords = array_values(array_filter(array_map('trim', $attributes['keywords'])));

        if ($keywords === []) {
            throw ValidationException::withMessages(['keywords' => 'Укажите хотя бы одно ключевое слово.']);
        }

        $attributes['keywords'] = $keywords;
        $attributes['minus_keywords'] = isset($attributes['minus_keywords'])
            ? array_values(array_filter(array_map('trim', $attributes['minus_keywords'])))
            : null;
        $attributes['name'] = ($attributes['name'] ?? null) ?: mb_substr(implode(', ', $keywords), 0, 120);

        return $attributes;
    }

    private function assertOwnership(Request $request, SearchQuery $query): void
    {
        abort_unless($query->user_id === $request->user()?->id, 404);
    }

    /** @return array<string, mixed> */
    private function queryDto(SearchQuery $query): array
    {
        return [
            'id' => $query->id,
            'name' => $query->name,
            'keywords' => $query->keywords,
            'minus_keywords' => $query->minus_keywords,
            'region' => $query->region,
            'budget_min' => $query->budget_min,
            'budget_max' => $query->budget_max,
            'deadline_from' => $query->deadline_from?->format('Y-m-d'),
            'deadline_to' => $query->deadline_to?->format('Y-m-d'),
            'status' => $query->status->value,
            'monitoring_started_at' => $query->monitoring_started_at?->toAtomString(),
        ];
    }
}
