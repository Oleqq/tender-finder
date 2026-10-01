<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\Tender;
use App\Tenders\RostenderAccessDisabledException;
use App\Tenders\TenderSourceItem;
use Illuminate\Support\Facades\Cache;

final class MonitoringPreviewService
{
    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function preview(array $attributes): array
    {
        $query = new SearchQuery($attributes);
        $templateId = $attributes['filters']['source']['rostender_template_id'] ?? null;
        if (! is_int($templateId) || $templateId < 1) {
            $feedIds = app(PublicTenderSources::class)->feeds()->modelKeys();
            if ($feedIds === []) {
                throw new RostenderAccessDisabledException;
            }
            $tenders = Tender::query()
                ->whereIn('source', app(PublicTenderSources::class)->enabled())
                ->whereHas('sourceFeedItem', fn ($items) => $items->whereIn('source_feed_id', $feedIds))
                ->where(fn ($items) => $items->whereNull('deadline_at')->orWhereDate('deadline_at', '>=', now()->toDateString()))
                ->latest('published_at')->limit(100)->get();

            return $this->evaluate($query, $tenders->all(), 'Проверка условий на последних 100 открытых карточках подключённых источников. После сохранения мониторинг проверит новые поступления.');
        }

        app(RostenderAccessGate::class)->assertDataProcessingAllowed();
        $items = Cache::remember('monitoring-preview:rostender:'.$templateId, now()->addMinutes(30), function () use ($templateId): array {
            $api = app(RostenderApiClient::class);
            $page = $api->template($templateId);

            return array_map(fn ($item) => $api->tender($item->id), array_slice($page->items, 0, 5));
        });
        $scope = 'Первые 5 карточек шаблона RosTender; результаты обновляются раз в 30 минут.';
        $tenders = array_map(fn (TenderSourceItem $item): Tender => new Tender([
            'title' => $item->title, 'description' => $item->summary, 'region' => $item->region,
            'budget_amount' => $item->budgetAmount, 'deadline_at' => $item->deadlineAt,
            'metadata' => $item->metadata, 'canonical_url' => $item->canonicalUrl,
        ]), $items);

        return $this->evaluate($query, $tenders, $scope);
    }

    /** @param list<Tender> $tenders
     * @return array<string, mixed>
     */
    private function evaluate(SearchQuery $query, array $tenders, string $scope): array
    {
        $matched = [];
        $excluded = [];
        $unknown = 0;
        foreach ($tenders as $tender) {
            $result = app(TenderMatchingService::class)->evaluate($query, $tender);
            if ($result->matches) {
                $matched[] = ['title' => $tender->title, 'url' => $tender->canonical_url, 'budget' => $tender->budget_amount, 'region' => $tender->region];
                if (in_array('unknown', $result->reasons, true)) {
                    $unknown++;
                }
            } else {
                $reason = $result->reasons['excluded_by'];
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
            }
        }

        return ['scope' => $scope, 'checked' => count($tenders), 'matched' => count($matched), 'unknown' => $unknown,
            'excluded' => $excluded, 'tenders' => array_slice($matched, 0, 10), 'checked_at' => now()->toAtomString()];
    }
}
