<?php

namespace App\Http\Controllers;

use App\Enums\QueryStatus;
use App\Models\SearchQuery;
use App\Services\LocalMvpEisRssSearchService;
use App\Services\LocalMvpOperatorService;
use App\Services\SearchQueryPresenter;
use App\Tenders\EisRssMatchMode;
use App\Tenders\EisRssRelevanceCriteria;
use App\Tenders\EisRssSearchCriteria;
use App\Tenders\RssSourceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SavedSearchRunController extends Controller
{
    public function __invoke(
        Request $request,
        SearchQuery $query,
        LocalMvpOperatorService $operator,
        LocalMvpEisRssSearchService $search,
        SearchQueryPresenter $presenter,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);
        abort_unless(
            $query->user_id === $request->user()?->id && $query->status !== QueryStatus::Deleted,
            404,
        );

        $source = is_array($query->filters) && is_array($query->filters['source'] ?? null)
            ? $query->filters['source']
            : [];
        $relevanceFilters = is_array($query->filters) && is_array($query->filters['relevance'] ?? null)
            ? $query->filters['relevance']
            : [];
        $phrase = trim(implode(' ', array_filter($query->keywords, 'is_string')));
        $relevance = new EisRssRelevanceCriteria(
            phrase: $phrase,
            matchMode: EisRssMatchMode::tryFrom(
                $this->nullableString($relevanceFilters['match_mode'] ?? null) ?? '',
            ) ?? EisRssMatchMode::All,
            minusKeywords: array_values(array_filter($query->minus_keywords ?? [], 'is_string')),
        );
        $criteria = new EisRssSearchCriteria(
            law44: (bool) ($source['law_44'] ?? false),
            law223: (bool) ($source['law_223'] ?? false),
            stageApplication: (bool) ($source['stage_application'] ?? false),
            stageCommission: (bool) ($source['stage_commission'] ?? false),
            stageCompleted: (bool) ($source['stage_completed'] ?? false),
            stageCancelled: (bool) ($source['stage_cancelled'] ?? false),
            jointPurchase: (bool) ($source['joint_purchase'] ?? false),
            placedBySeparateSubdivision: (bool) ($source['placed_by_separate_subdivision'] ?? false),
            unionStateBudget: (bool) ($source['union_state_budget'] ?? false),
            createdByCustomerRepresentative: (bool) ($source['created_by_customer_representative'] ?? false),
            smpSono: (bool) ($source['smp_sono'] ?? false),
            budgetFrom: $this->nullableString($source['budget_from'] ?? null),
            budgetTo: $this->nullableString($source['budget_to'] ?? null),
            publishedFrom: $this->nullableString($source['published_from'] ?? null),
            publishedTo: $this->nullableString($source['published_to'] ?? null),
        );

        try {
            $result = $search->run(
                $request->user(),
                $relevance,
                $this->nullableString($source['rss_url'] ?? null),
                (int) ($source['pages'] ?? 3),
                $criteria,
                $query,
            );
        } catch (RssSourceException $exception) {
            throw ValidationException::withMessages([
                'query' => $this->errorMessage($exception->codeName),
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'query' => 'ЕИС временно недоступна. Повторите запуск позже.',
            ]);
        }

        $query->load('latestManualRun');

        return response()->json([
            'preview' => [
                'items_seen' => $result->preview->itemsSeen,
                'items_matched' => $result->preview->itemsMatched,
                'items_created' => $result->preview->itemsCreated,
                'pages_requested' => $result->preview->pagesRequested,
                'pages_loaded' => $result->preview->pagesLoaded,
                'partially_loaded' => $result->preview->partiallyLoaded,
            ],
            'tenders' => $result->tenders,
            'query' => $presenter->toArray($query),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'url_not_allowed' => 'Сохранённая RSS-ссылка больше не разрешена. Измените условия запроса.',
            'feed_not_manual' => 'Сохранённая лента недоступна для ручного запуска.',
            'invalid_query' => 'В сохранённом запросе должна быть фраза от двух до 120 символов.',
            'tls_failed' => 'Сервер не смог безопасно проверить сертификат ЕИС. Попробуйте позже.',
            default => 'ЕИС временно не ответила. Повторите запуск позже.',
        };
    }
}
