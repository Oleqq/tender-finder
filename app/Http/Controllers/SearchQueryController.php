<?php

namespace App\Http\Controllers;

use App\Models\SearchQuery;
use App\Services\QueryAccessDeniedException;
use App\Services\QueryLimitReachedException;
use App\Services\SearchQueryPresenter;
use App\Services\SearchQueryService;
use App\Tenders\EisRssUrlValidator;
use App\Tenders\RssSourceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SearchQueryController extends Controller
{
    public function __construct(
        private readonly EisRssUrlValidator $eisRssUrls,
        private readonly SearchQueryPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('MyQueries', [
            'queries' => $request->user()
                ->searchQueries()
                ->where('status', '!=', 'deleted')
                ->with('latestManualRun')
                ->latest()
                ->get()
                ->map(fn (SearchQuery $query): array => $this->presenter->toArray($query))
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

        return response()->json(['query' => $this->presenter->toArray($query)], 201);
    }

    public function update(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->presenter->toArray($queries->update($query, $this->validatedAttributes($request)))]);
    }

    public function pause(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->presenter->toArray($queries->pause($query))]);
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

        return response()->json(['query' => $this->presenter->toArray($query)]);
    }

    public function freeze(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->presenter->toArray($queries->freeze($query))]);
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
            'filters' => ['nullable', 'array:source'],
            'filters.source' => ['nullable', 'array:law_44,law_223,stage_application,stage_commission,stage_completed,stage_cancelled,joint_purchase,placed_by_separate_subdivision,union_state_budget,created_by_customer_representative,smp_sono,budget_from,budget_to,published_from,published_to,pages,rss_url'],
            'filters.source.law_44' => ['nullable', 'boolean'],
            'filters.source.law_223' => ['nullable', 'boolean'],
            'filters.source.stage_application' => ['nullable', 'boolean'],
            'filters.source.stage_commission' => ['nullable', 'boolean'],
            'filters.source.stage_completed' => ['nullable', 'boolean'],
            'filters.source.stage_cancelled' => ['nullable', 'boolean'],
            'filters.source.joint_purchase' => ['nullable', 'boolean'],
            'filters.source.placed_by_separate_subdivision' => ['nullable', 'boolean'],
            'filters.source.union_state_budget' => ['nullable', 'boolean'],
            'filters.source.created_by_customer_representative' => ['nullable', 'boolean'],
            'filters.source.smp_sono' => ['nullable', 'boolean'],
            'filters.source.budget_from' => ['nullable', 'numeric', 'min:0'],
            'filters.source.budget_to' => ['nullable', 'numeric', 'min:0', 'gte:filters.source.budget_from'],
            'filters.source.published_from' => ['nullable', 'date_format:Y-m-d'],
            'filters.source.published_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filters.source.published_from'],
            'filters.source.pages' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('tender.rss.manual_search_max_pages', 3))],
            'filters.source.rss_url' => ['nullable', 'url', 'max:2000'],
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
        $this->normalizeEisSourceFilters($attributes);

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    private function normalizeEisSourceFilters(array &$attributes): void
    {
        $source = $attributes['filters']['source'] ?? null;

        if (! is_array($source)) {
            return;
        }

        $this->validateSourceStages($source);

        $rssUrl = $this->nullableString($source['rss_url'] ?? null);

        if ($rssUrl !== null) {
            try {
                $rssUrl = $this->eisRssUrls->canonicalFeedUrl($rssUrl);
            } catch (RssSourceException) {
                throw ValidationException::withMessages([
                    'filters.source.rss_url' => 'Сохранить можно только RSS-ссылку расширенного поиска ЕИС.',
                ]);
            }
        }

        $attributes['filters'] = [
            'source' => [
                'law_44' => (bool) ($source['law_44'] ?? false),
                'law_223' => (bool) ($source['law_223'] ?? false),
                'stage_application' => (bool) ($source['stage_application'] ?? false),
                'stage_commission' => (bool) ($source['stage_commission'] ?? false),
                'stage_completed' => (bool) ($source['stage_completed'] ?? false),
                'stage_cancelled' => (bool) ($source['stage_cancelled'] ?? false),
                'joint_purchase' => (bool) ($source['joint_purchase'] ?? false),
                'placed_by_separate_subdivision' => (bool) ($source['placed_by_separate_subdivision'] ?? false),
                'union_state_budget' => (bool) ($source['union_state_budget'] ?? false),
                'created_by_customer_representative' => (bool) ($source['created_by_customer_representative'] ?? false),
                'smp_sono' => (bool) ($source['smp_sono'] ?? false),
                'budget_from' => $this->nullableString($source['budget_from'] ?? null),
                'budget_to' => $this->nullableString($source['budget_to'] ?? null),
                'published_from' => $this->nullableString($source['published_from'] ?? null),
                'published_to' => $this->nullableString($source['published_to'] ?? null),
                'pages' => (int) ($source['pages'] ?? 3),
                'rss_url' => $rssUrl,
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $source */
    private function validateSourceStages(array $source): void
    {
        if ($this->nullableString($source['rss_url'] ?? null) !== null) {
            return;
        }

        $stageKeys = [
            'stage_application',
            'stage_commission',
            'stage_completed',
            'stage_cancelled',
        ];
        $providedStages = array_filter(
            $stageKeys,
            fn (string $key): bool => array_key_exists($key, $source),
        );
        $selectedStages = array_filter(
            $stageKeys,
            fn (string $key): bool => (bool) ($source[$key] ?? false),
        );

        if ($providedStages !== [] && $selectedStages === []) {
            throw ValidationException::withMessages([
                'filters.source.stage_application' => 'Выберите хотя бы один этап закупки.',
            ]);
        }
    }

    private function assertOwnership(Request $request, SearchQuery $query): void
    {
        abort_unless($query->user_id === $request->user()?->id, 404);
    }
}
