<?php

namespace App\Services;

use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Builder;

final class CachedMonitoringMatchService
{
    public function fill(SearchQuery $query): int
    {
        $feedIds = RostenderFeedSearchQuery::query()
            ->where('search_query_id', $query->id)
            ->pluck('source_feed_id');

        if ($feedIds->isEmpty()) {
            return 0;
        }

        $created = 0;
        Tender::query()
            ->where('source', 'rostender')
            ->whereHas('sourceFeedItem', fn (Builder $items) => $items->whereIn('source_feed_id', $feedIds))
            ->where(fn (Builder $tenders) => $tenders->whereNull('deadline_at')
                ->orWhereDate('deadline_at', '>=', now()->toDateString()))
            ->chunkById(200, function ($tenders) use ($query, &$created): void {
                foreach ($tenders as $tender) {
                    // Existing cards appear in the feed without a burst of
                    // historical Telegram notifications.
                    $created += app(TenderMatchingService::class)
                        ->matchTenderForQueries($tender, [$query], false);
                }
            });

        return $created;
    }
}
