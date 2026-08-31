<?php

namespace App\Services;

use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;

final class SearchQueryPresenter
{
    /** @return array<string, mixed> */
    public function toArray(SearchQuery $query): array
    {
        /** @var LocalMvpSearchSnapshot|null $lastRun */
        $lastRun = $query->relationLoaded('latestManualRun')
            ? $query->latestManualRun
            : $query->latestManualRun()->first();

        return [
            'id' => $query->id,
            'name' => $query->name,
            'phrase' => implode(' ', array_filter($query->keywords, 'is_string')),
            'keywords' => $query->keywords,
            'minus_keywords' => $query->minus_keywords,
            'region' => $query->region,
            'budget_min' => $query->budget_min,
            'budget_max' => $query->budget_max,
            'deadline_from' => $query->deadline_from?->format('Y-m-d'),
            'deadline_to' => $query->deadline_to?->format('Y-m-d'),
            'filters' => $query->filters,
            'status' => $query->status->value,
            'monitoring_started_at' => $query->monitoring_started_at?->toAtomString(),
            'last_run_at' => $lastRun?->created_at?->toAtomString(),
            'last_run' => $lastRun === null ? null : [
                'items_seen' => $lastRun->items_seen,
                'items_matched' => $lastRun->items_matched,
                'items_created' => $lastRun->items_created,
                'pages_requested' => $lastRun->pages_requested,
                'pages_loaded' => $lastRun->pages_loaded,
                'partially_loaded' => $lastRun->partially_loaded,
            ],
        ];
    }
}
