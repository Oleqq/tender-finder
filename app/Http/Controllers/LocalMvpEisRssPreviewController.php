<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpEisRssImportService;
use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpSearchSnapshotService;
use App\Services\LocalMvpTenderWorkspaceService;
use App\Tenders\EisRssSearchCriteria;
use App\Tenders\EisRssSearchUrlFactory;
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
        LocalMvpEisRssImportService $importer,
        LocalMvpSearchSnapshotService $snapshots,
        LocalMvpTenderWorkspaceService $workspace,
        EisRssSearchUrlFactory $searchUrls,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);

        $attributes = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'url' => ['nullable', 'string', 'max:2000'],
            'pages' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('tender.rss.manual_search_max_pages', 3))],
            'law_44' => ['nullable', 'boolean'],
            'law_223' => ['nullable', 'boolean'],
            'budget_from' => ['nullable', 'regex:/^\d{1,13}(?:[.,]\d{1,2})?$/'],
            'budget_to' => ['nullable', 'regex:/^\d{1,13}(?:[.,]\d{1,2})?$/'],
            'published_from' => ['nullable', 'date_format:Y-m-d'],
            'published_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:published_from'],
        ]);

        $this->validateRanges($attributes);
        $criteria = new EisRssSearchCriteria(
            law44: (bool) ($attributes['law_44'] ?? false),
            law223: (bool) ($attributes['law_223'] ?? false),
            budgetFrom: $attributes['budget_from'] ?? null,
            budgetTo: $attributes['budget_to'] ?? null,
            publishedFrom: $attributes['published_from'] ?? null,
            publishedTo: $attributes['published_to'] ?? null,
        );

        $url = filled($attributes['url'] ?? null)
            ? $attributes['url']
            : $searchUrls->forPhrase($attributes['query'], $criteria);

        $errorField = filled($attributes['url'] ?? null) ? 'url' : 'query';
        $pages = (int) ($attributes['pages'] ?? config('tender.rss.manual_search_max_pages', 3));

        try {
            $result = $importer->import($url, $attributes['query'], $pages);
        } catch (RssSourceException $exception) {
            throw ValidationException::withMessages([
                $errorField => $this->errorMessage($exception->codeName),
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $errorField => 'ЕИС временно недоступна. Повторите поиск позже.',
            ]);
        }

        $tenders = $workspace->tendersForSourceExternalIds(
            $request->user(),
            'eis_rss',
            $result->externalIds,
        );
        $snapshots->remember(
            $request->user(),
            $attributes['query'],
            $result,
            array_map(fn (array $tender): int => $tender['id'], $tenders),
        );

        return response()->json([
            'preview' => [
                'items_seen' => $result->itemsSeen,
                'items_matched' => $result->itemsMatched,
                'items_created' => $result->itemsCreated,
                'pages_requested' => $result->pagesRequested,
                'pages_loaded' => $result->pagesLoaded,
                'partially_loaded' => $result->partiallyLoaded,
            ],
            'tenders' => $tenders,
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
}
