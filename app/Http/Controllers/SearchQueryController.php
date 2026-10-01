<?php

namespace App\Http\Controllers;

use App\Enums\QueryStatus;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Services\AccessService;
use App\Services\B2bCenterSearchService;
use App\Services\CachedMonitoringMatchService;
use App\Services\MonitoringPreviewService;
use App\Services\MonitoringStatusService;
use App\Services\PublicTenderSources;
use App\Services\QueryAccessDeniedException;
use App\Services\QueryLimitReachedException;
use App\Services\RostenderAccessGate;
use App\Services\RostenderPollingDispatcher;
use App\Services\RostenderQuotaGuard;
use App\Services\RostenderTemplateCatalog;
use App\Services\SearchQueryPresenter;
use App\Services\SearchQueryService;
use App\Tenders\RostenderAccessDisabledException;
use App\Tenders\RostenderApiException;
use App\Tenders\RostenderQuotaExceededException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SearchQueryController extends Controller
{
    public function __construct(
        private readonly SearchQueryPresenter $presenter,
        private readonly RostenderTemplateCatalog $rostenderTemplates,
    ) {}

    public function index(Request $request, MonitoringStatusService $statuses): Response
    {
        $queries = $request->user()
            ->searchQueries()
            ->where('status', '!=', 'deleted')
            ->with('latestManualRun')
            ->latest()
            ->get();
        $sourceStatuses = $statuses->forQueries($queries);

        return Inertia::render('MyQueries', [
            'publicSources' => app(PublicTenderSources::class)->feeds()->pluck('source')->unique()->values(),
            'queries' => $queries
                ->map(fn (SearchQuery $query): array => [
                    ...$this->presenter->toArray($query),
                    'source_statuses' => $sourceStatuses[$query->id],
                ])
                ->values(),
            'rostenderTemplates' => collect($this->rostenderTemplates->available())
                ->map(fn ($template): array => ['id' => $template->id, 'name' => $template->name])
                ->values(),
        ]);
    }

    public function preview(Request $request, AccessService $access, MonitoringPreviewService $preview): JsonResponse
    {
        abort_unless($access->hasActiveAccess($request->user()), 403);
        $attributes = $this->validatedAttributes($request);
        try {
            return response()->json($preview->preview($attributes));
        } catch (RostenderQuotaExceededException) {
            return response()->json(['message' => 'Лимит проверок источника исчерпан. Попробуйте позже.'], 429);
        } catch (RostenderApiException|RostenderAccessDisabledException) {
            return response()->json(['message' => 'Источник сейчас недоступен. Это не означает, что подходящих тендеров нет. Попробуйте позже или сохраните мониторинг.'], 503);
        }
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
        } catch (RostenderAccessDisabledException) {
            throw ValidationException::withMessages([
                'filters.source.rostender_template_id' => 'RosTender пока недоступен для вашего тарифа.',
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'filters.source.rostender_template_id' => $exception->getMessage() === 'rostender_monitoring_limit_reached'
                    ? 'Достигнут лимит RosTender-мониторингов для вашего тарифа.'
                    : 'Не удалось подключить шаблон RosTender. Попробуйте ещё раз.',
            ]);
        }

        $cachedMatches = app(CachedMonitoringMatchService::class)->fill($query);

        return response()->json([
            'query' => $this->queryPayload($query),
            'cached_matches' => $cachedMatches,
            'check_queued' => $this->dispatchDueSourceCheck($query),
        ], 201);
    }

    public function quick(Request $request, SearchQueryService $queries): JsonResponse
    {
        $data = $request->validate([
            'phrase' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $phrase = trim($data['phrase']);
        if (mb_strlen($phrase) < 2) {
            throw ValidationException::withMessages(['phrase' => 'Введите хотя бы два символа.']);
        }
        if (! app(AccessService::class)->hasActiveAccess($request->user())) {
            throw ValidationException::withMessages([
                'monitoring' => 'Для запуска поиска нужен активный доступ. Проверьте тариф в профиле.',
            ]);
        }
        $publicSources = app(PublicTenderSources::class);
        $publicSources->synchronize();
        $hasPublicSources = $publicSources->feeds()->isNotEmpty();
        $rostenderEnabled = app(RostenderAccessGate::class)->allowsDataProcessing();
        if (! $rostenderEnabled && ! $hasPublicSources) {
            throw ValidationException::withMessages([
                'monitoring' => 'Источники сейчас не подключены. Поиск не запущен.',
            ]);
        }

        $feeds = SourceFeed::query()->where('source', 'rostender')
            ->where('status', 'active')->whereNotNull('source_identifier')
            ->get(['source_identifier']);
        $templateId = $rostenderEnabled && $feeds->count() === 1
            ? (int) $feeds->first()->source_identifier
            : null;
        if ($rostenderEnabled && $feeds->isEmpty() && ! $hasPublicSources) {
            $templates = $this->rostenderTemplates->available();
            $templateId = count($templates) === 1 ? $templates[0]->id : null;
        }
        if ($templateId === null && ! $hasPublicSources) {
            throw ValidationException::withMessages([
                'monitoring' => 'Для быстрого поиска нужен один подключённый шаблон RosTender. Настройте мониторинг вручную.',
            ]);
        }
        $keywords = array_slice(preg_split('/\s+/u', $phrase) ?: [], 0, 20);

        $existing = SearchQuery::query()->where('user_id', $request->user()->id)
            ->where('status', 'active')->where('name', $phrase)->get()
            ->first(fn (SearchQuery $query): bool => $query->keywords === $keywords
                && (($query->filters['source']['rostender_template_id'] ?? null) === $templateId
                    || (! $rostenderEnabled && $hasPublicSources)));
        if ($existing !== null) {
            app(B2bCenterSearchService::class)->synchronize($existing);

            return response()->json([
                'query' => $this->queryPayload($existing),
                'cached_matches' => app(CachedMonitoringMatchService::class)->fill($existing),
                'check_queued' => $this->dispatchDueSourceCheck($existing),
                'reused' => true,
            ]);
        }
        try {
            $query = $queries->create($request->user(), [
                'name' => $phrase,
                'keywords' => $keywords,
                'filters' => ['source' => ['rostender_template_id' => $templateId]],
            ]);
        } catch (QueryAccessDeniedException|QueryLimitReachedException|RostenderAccessDisabledException) {
            throw ValidationException::withMessages([
                'monitoring' => 'Не удалось включить мониторинг. Проверьте доступ и число активных мониторингов.',
            ]);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'monitoring' => 'Этот шаблон сейчас недоступен для нового мониторинга.',
            ]);
        }

        $cachedMatches = app(CachedMonitoringMatchService::class)->fill($query);

        return response()->json([
            'query' => $this->queryPayload($query),
            'cached_matches' => $cachedMatches,
            'check_queued' => $this->dispatchDueSourceCheck($query),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function queryPayload(SearchQuery $query): array
    {
        return [...$this->presenter->toArray($query),
            'source_statuses' => app(MonitoringStatusService::class)->forQueries(collect([$query]))[$query->id]];
    }

    private function dispatchDueSourceCheck(SearchQuery $query): bool
    {
        $publicQueued = app(PublicTenderSources::class)->dispatchDueChecks($query);
        if (! app(RostenderAccessGate::class)->allowsDataProcessing()
            || ! app(RostenderQuotaGuard::class)->normalCapacityAvailable()) {
            return $publicQueued;
        }

        $feedIds = RostenderFeedSearchQuery::query()
            ->where('search_query_id', $query->id)->pluck('source_feed_id')->all();

        return app(RostenderPollingDispatcher::class)->dispatchOneDueFeed($feedIds) || $publicQueued;
    }

    public function update(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        try {
            $query = $queries->update($query, $this->validatedAttributes($request));
        } catch (RostenderAccessDisabledException) {
            throw ValidationException::withMessages([
                'filters.source.rostender_template_id' => 'RosTender пока недоступен для вашего тарифа.',
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'filters.source.rostender_template_id' => $exception->getMessage() === 'rostender_monitoring_limit_reached'
                    ? 'Достигнут лимит RosTender-мониторингов для вашего тарифа.'
                    : 'Не удалось подключить шаблон RosTender. Попробуйте ещё раз.',
            ]);
        }

        if ($query->status === QueryStatus::Active) {
            app(CachedMonitoringMatchService::class)->fill($query);
            $this->dispatchDueSourceCheck($query);
        }

        return response()->json(['query' => $this->queryPayload($query)]);
    }

    public function pause(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->queryPayload($queries->pause($query))]);
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

        if ($query->status === QueryStatus::Active) {
            app(CachedMonitoringMatchService::class)->fill($query);
            $this->dispatchDueSourceCheck($query);
        }

        return response()->json(['query' => $this->queryPayload($query)]);
    }

    public function freeze(Request $request, SearchQuery $query, SearchQueryService $queries): JsonResponse
    {
        $this->assertOwnership($request, $query);

        return response()->json(['query' => $this->queryPayload($queries->freeze($query))]);
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
        $isPartialUpdate = $request->isMethod('patch');
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
            'filters' => [$isPartialUpdate ? 'sometimes' : 'required', 'array:source,relevance,excluded_customers'],
            'filters.excluded_customers' => ['sometimes', 'array', 'max:50'],
            'filters.excluded_customers.*' => ['required', 'string', 'max:200', 'distinct'],
            'filters.relevance' => ['nullable', 'array:match_mode'],
            'filters.relevance.match_mode' => ['nullable', 'string', 'in:all,any,exact'],
            'filters.source' => [$isPartialUpdate ? 'sometimes' : 'required', 'array:rostender_template_id'],
            'filters.source.rostender_template_id' => ['nullable', 'integer', 'min:1'],
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
        if (! $isPartialUpdate || isset($attributes['filters']['source'])) {
            $this->normalizeSourceFilters($attributes);
        }
        $this->normalizeRelevanceFilters($attributes);

        return $attributes;
    }

    /** @param array<string, mixed> $attributes */
    private function normalizeSourceFilters(array &$attributes): void
    {
        $source = $attributes['filters']['source'] ?? null;

        if (! is_array($source)) {
            throw ValidationException::withMessages([
                'filters.source.rostender_template_id' => 'Выберите шаблон RosTender.',
            ]);
        }

        $rostenderTemplateId = isset($source['rostender_template_id'])
            ? (int) $source['rostender_template_id']
            : null;

        if ($rostenderTemplateId === null && app(PublicTenderSources::class)->feeds()->isNotEmpty()) {
            $attributes['filters']['source'] = ['rostender_template_id' => null];

            return;
        }
        if ($rostenderTemplateId === null || ! $this->rostenderTemplates->contains($rostenderTemplateId)) {
            throw ValidationException::withMessages([
                'filters.source.rostender_template_id' => 'Выберите доступный шаблон RosTender.',
            ]);
        }

        $attributes['filters'] = [
            ...$attributes['filters'],
            'source' => [
                'rostender_template_id' => $rostenderTemplateId,
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

    private function assertOwnership(Request $request, SearchQuery $query): void
    {
        abort_unless($query->user_id === $request->user()?->id, 404);
    }
}
