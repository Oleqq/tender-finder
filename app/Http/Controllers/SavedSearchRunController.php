<?php

namespace App\Http\Controllers;

use App\Enums\AccessState;
use App\Enums\QueryStatus;
use App\Models\SearchQuery;
use App\Services\AccessService;
use App\Services\LocalMvpEisRssSearchService;
use App\Services\SearchQueryPresenter;
use App\Services\SourceFeedService;
use App\Tenders\EisRssMatchMode;
use App\Tenders\EisRssRelevanceCriteria;
use App\Tenders\EisRssSearchCriteria;
use App\Tenders\EisRssSearchUrlFactory;
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
        AccessService $access,
        LocalMvpEisRssSearchService $search,
        SearchQueryPresenter $presenter,
        SourceFeedService $feeds,
        EisRssSearchUrlFactory $searchUrls,
    ): JsonResponse {
        abort_unless(
            $query->user_id === $request->user()?->id && $query->status !== QueryStatus::Deleted,
            404,
        );
        abort_unless(
            in_array($access->snapshotFor($request->user())->state, [AccessState::Trialing, AccessState::Active], true),
            403,
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
            regions: $this->regionItems($source['regions'] ?? null),
            okpd2: $this->okpd2Items($source['okpd2'] ?? null),
            okpd2WithNested: (bool) ($source['okpd2_with_nested'] ?? true),
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
            $feeds->findOrCreate(
                $this->nullableString($source['rss_url'] ?? null)
                    ?? $searchUrls->forPhrase($relevance->phrase, $criteria),
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

    /** @return list<array{code: string, name: string}> */
    private function regionItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_array($item)
            && is_string($item['code'] ?? null)
            && is_string($item['name'] ?? null)));
    }

    /** @return list<array{id: string, code: string, name: string}> */
    private function okpd2Items(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_array($item)
            && is_string($item['id'] ?? null)
            && is_string($item['code'] ?? null)
            && is_string($item['name'] ?? null)));
    }
}
