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
            ->when(! app(RostenderAccessGate::class)->allowsDataProcessing(), fn ($links) => $links->whereRaw('1 = 0'))
            ->pluck('source_feed_id');

        $publicFeedIds = app(PublicTenderSources::class)->feeds($query)->modelKeys();
        if ($feedIds->isEmpty() && $publicFeedIds === []) {
            return 0;
        }

        $created = 0;
        Tender::query()
            ->where(function (Builder $tenders) use ($feedIds, $publicFeedIds): void {
                $tenders->where(fn (Builder $rostender) => $rostender->where('source', 'rostender')
                    ->whereHas('sourceFeedItem', fn (Builder $items) => $items->whereIn('source_feed_id', $feedIds)))
                    ->orWhere(fn (Builder $public) => $public->whereIn('source', app(PublicTenderSources::class)->enabled())
                        ->whereExists(fn ($items) => $items->selectRaw('1')->from('source_feed_items')
                            ->join('source_feeds', 'source_feeds.id', '=', 'source_feed_items.source_feed_id')
                            ->whereColumn('source_feed_items.external_id', 'tenders.external_id')
                            ->whereColumn('source_feeds.source', 'tenders.source')
                            ->whereIn('source_feed_items.source_feed_id', $publicFeedIds)));
            })
            ->where(fn (Builder $tenders) => $tenders->whereNull('deadline_at')
                ->orWhere('deadline_at', '>=', now()))
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
