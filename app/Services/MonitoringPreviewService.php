<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Tenders\EisRssSearchCriteria;
use App\Tenders\EisRssSearchUrlFactory;
use App\Tenders\EisRssSource;
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
        if ($templateId !== null) {
            app(RostenderAccessGate::class)->assertDataProcessingAllowed();
            $items = Cache::remember('monitoring-preview:rostender:'.$templateId, now()->addMinutes(30), function () use ($templateId): array {
                $api = app(RostenderApiClient::class);
                $page = $api->template($templateId);

                return array_map(fn ($item) => $api->tender($item->id), array_slice($page->items, 0, 5));
            });
            $scope = 'Первые 5 карточек шаблона RosTender; результаты обновляются раз в 30 минут.';
        } else {
            $url = app(EisRssSearchUrlFactory::class)->forPhrase(implode(' ', $query->keywords), new EisRssSearchCriteria(stageApplication: true));
            $items = app(EisRssSource::class)->fetch(new SourceFeed(['canonical_url' => $url]))->items;
            $scope = 'Одна RSS-страница ЕИС по ключевым словам, на этапе подачи заявок.';
        }
        $matched = [];
        $excluded = [];
        $unknown = 0;
        foreach ($items as $item) {
            /** @var TenderSourceItem $item */
            $tender = new Tender([
                'title' => $item->title, 'description' => $item->summary, 'region' => $item->region,
                'budget_amount' => $item->budgetAmount, 'deadline_at' => $item->deadlineAt, 'metadata' => $item->metadata,
            ]);
            $result = app(TenderMatchingService::class)->evaluate($query, $tender);
            if ($result->matches) {
                $matched[] = ['title' => $item->title, 'url' => $item->canonicalUrl, 'budget' => $item->budgetAmount, 'region' => $item->region];
                if (in_array('unknown', $result->reasons, true)) {
                    $unknown++;
                }
            } else {
                $reason = $result->reasons['excluded_by'];
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
            }
        }

        return ['scope' => $scope, 'checked' => count($items), 'matched' => count($matched), 'unknown' => $unknown,
            'excluded' => $excluded, 'tenders' => array_slice($matched, 0, 10), 'checked_at' => now()->toAtomString()];
    }
}
