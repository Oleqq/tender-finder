<?php

namespace App\Http\Controllers;

use App\Models\SearchQuery;
use App\Services\QueryAccessDeniedException;
use App\Services\QueryLimitReachedException;
use App\Services\SearchQueryPresenter;
use App\Services\SearchQueryService;
use App\Tenders\EisRegionCatalog;
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
        private readonly EisRegionCatalog $regions,
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
            'filters' => ['nullable', 'array:source,relevance'],
            'filters.relevance' => ['nullable', 'array:match_mode'],
            'filters.relevance.match_mode' => ['nullable', 'string', 'in:all,any,exact'],
            'filters.source' => ['nullable', 'array:law_44,law_223,stage_application,stage_commission,stage_completed,stage_cancelled,joint_purchase,placed_by_separate_subdivision,union_state_budget,created_by_customer_representative,smp_sono,budget_from,budget_to,published_from,published_to,regions,okpd2,okpd2_with_nested,pages,rss_url'],
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
            'filters.source.regions' => ['nullable', 'array', 'max:5'],
            'filters.source.regions.*' => ['required', 'array:code,name'],
            'filters.source.regions.*.code' => ['required', 'string', 'regex:/^\d{11}$/', 'distinct'],
            'filters.source.regions.*.name' => ['required', 'string', 'max:120'],
            'filters.source.okpd2' => ['nullable', 'array', 'max:5'],
            'filters.source.okpd2.*' => ['required', 'array:id,code,name'],
            'filters.source.okpd2.*.id' => ['required', 'string', 'regex:/^\d{1,12}$/', 'distinct'],
            'filters.source.okpd2.*.code' => ['required', 'string', 'regex:/^(?:[A-U]|\d{2}(?:\.\d{1,3}){0,3})$/', 'distinct'],
            'filters.source.okpd2.*.name' => ['required', 'string', 'max:500'],
            'filters.source.okpd2_with_nested' => ['nullable', 'boolean'],
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
        $this->normalizeRelevanceFilters($attributes);

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
        $this->validateSourceRegions($source['regions'] ?? []);

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
            ...$attributes['filters'],
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
                'regions' => is_array($source['regions'] ?? null) ? array_values($source['regions']) : [],
                'okpd2' => is_array($source['okpd2'] ?? null) ? array_values($source['okpd2']) : [],
                'okpd2_with_nested' => (bool) ($source['okpd2_with_nested'] ?? true),
                'pages' => (int) ($source['pages'] ?? 3),
                'rss_url' => $rssUrl,
            ],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function normalizeRelevanceFilters(array &$attributes): void
    {
        $relevance = $attributes['filters']['relevance'] ?? null;

        if (! is_array($relevance)) {
            return;
        }

        $attributes['filters'] = [
            ...$attributes['filters'],
            'relevance' => [
                'match_mode' => $this->nullableString($relevance['match_mode'] ?? null) ?? 'all',
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

    private function validateSourceRegions(mixed $items): void
    {
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            $code = is_array($item) && is_string($item['code'] ?? null)
                ? $item['code']
                : '';

            if (! $this->regions->contains($code)) {
                throw ValidationException::withMessages([
                    "filters.source.regions.{$index}.code" => 'Выберите регион из справочника ЕИС.',
                ]);
            }
        }
    }

    private function assertOwnership(Request $request, SearchQuery $query): void
    {
        abort_unless($query->user_id === $request->user()?->id, 404);
    }
}
