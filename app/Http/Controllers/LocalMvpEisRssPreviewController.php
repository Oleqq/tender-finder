<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpEisRssSearchService;
use App\Services\LocalMvpOperatorService;
use App\Tenders\EisRssSearchCriteria;
use App\Tenders\RssSourceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocalMvpEisRssPreviewController extends Controller
{
    public function store(
        Request $request,
        LocalMvpOperatorService $operator,
        LocalMvpEisRssSearchService $search,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);

        $attributes = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'url' => ['nullable', 'string', 'max:2000'],
            'pages' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('tender.rss.manual_search_max_pages', 3))],
            'law_44' => ['nullable', 'boolean'],
            'law_223' => ['nullable', 'boolean'],
            'stage_application' => ['nullable', 'boolean'],
            'stage_commission' => ['nullable', 'boolean'],
            'stage_completed' => ['nullable', 'boolean'],
            'stage_cancelled' => ['nullable', 'boolean'],
            'joint_purchase' => ['nullable', 'boolean'],
            'placed_by_separate_subdivision' => ['nullable', 'boolean'],
            'union_state_budget' => ['nullable', 'boolean'],
            'created_by_customer_representative' => ['nullable', 'boolean'],
            'smp_sono' => ['nullable', 'boolean'],
            'budget_from' => ['nullable', 'regex:/^\d{1,13}(?:[.,]\d{1,2})?$/'],
            'budget_to' => ['nullable', 'regex:/^\d{1,13}(?:[.,]\d{1,2})?$/'],
            'published_from' => ['nullable', 'date_format:Y-m-d'],
            'published_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:published_from'],
        ]);

        $this->validateRanges($attributes);
        $this->validateStages($attributes);
        $criteria = new EisRssSearchCriteria(
            law44: (bool) ($attributes['law_44'] ?? false),
            law223: (bool) ($attributes['law_223'] ?? false),
            stageApplication: (bool) ($attributes['stage_application'] ?? false),
            stageCommission: (bool) ($attributes['stage_commission'] ?? false),
            stageCompleted: (bool) ($attributes['stage_completed'] ?? false),
            stageCancelled: (bool) ($attributes['stage_cancelled'] ?? false),
            jointPurchase: (bool) ($attributes['joint_purchase'] ?? false),
            placedBySeparateSubdivision: (bool) ($attributes['placed_by_separate_subdivision'] ?? false),
            unionStateBudget: (bool) ($attributes['union_state_budget'] ?? false),
            createdByCustomerRepresentative: (bool) ($attributes['created_by_customer_representative'] ?? false),
            smpSono: (bool) ($attributes['smp_sono'] ?? false),
            budgetFrom: $attributes['budget_from'] ?? null,
            budgetTo: $attributes['budget_to'] ?? null,
            publishedFrom: $attributes['published_from'] ?? null,
            publishedTo: $attributes['published_to'] ?? null,
        );

        $errorField = filled($attributes['url'] ?? null) ? 'url' : 'query';
        $pages = (int) ($attributes['pages'] ?? config('tender.rss.manual_search_max_pages', 3));

        try {
            $result = $search->run(
                $request->user(),
                $attributes['query'],
                $attributes['url'] ?? null,
                $pages,
                $criteria,
            );
        } catch (RssSourceException $exception) {
            throw ValidationException::withMessages([
                $errorField => $this->errorMessage($exception->codeName),
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $errorField => 'ЕИС временно недоступна. Повторите поиск позже.',
            ]);
        }

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
        ]);
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'url_not_allowed' => 'Вставьте RSS-ссылку расширенного поиска с zakupki.gov.ru.',
            'feed_not_manual' => 'Эта лента уже относится к отдельной настройке и не может быть загружена здесь.',
            'invalid_query' => 'Введите фразу от двух до 120 символов.',
            'tls_failed' => 'Сервер не смог безопасно проверить сертификат ЕИС. Попробуйте позже.',
            default => 'ЕИС временно не ответила. Повторите поиск позже.',
        };
    }

    /** @param array<string, mixed> $attributes */
    private function validateRanges(array $attributes): void
    {
        $from = $this->number($attributes['budget_from'] ?? null);
        $to = $this->number($attributes['budget_to'] ?? null);

        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages([
                'budget_to' => 'Верхняя граница НМЦК не может быть меньше нижней.',
            ]);
        }
    }

    private function number(mixed $value): ?float
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (float) str_replace(',', '.', $value);
    }

    /** @param array<string, mixed> $attributes */
    private function validateStages(array $attributes): void
    {
        if (filled($attributes['url'] ?? null)) {
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
            fn (string $key): bool => array_key_exists($key, $attributes),
        );
        $selectedStages = array_filter(
            $stageKeys,
            fn (string $key): bool => (bool) ($attributes[$key] ?? false),
        );

        if ($providedStages !== [] && $selectedStages === []) {
            throw ValidationException::withMessages([
                'stage_application' => 'Выберите хотя бы один этап закупки.',
            ]);
        }
    }
}
