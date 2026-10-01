<?php

namespace App\Services;

use App\Enums\QueryStatus;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;
use Illuminate\Support\Facades\DB;

final class B2bCenterSearchService
{
    public function synchronize(SearchQuery $query): ?SourceFeed
    {
        return DB::transaction(fn () => $this->synchronizeLocked(
            SearchQuery::query()->lockForUpdate()->findOrFail($query->id),
        ));
    }

    private function synchronizeLocked(SearchQuery $query): ?SourceFeed
    {
        $oldIds = SourceFeedSearchQuery::query()->where('search_query_id', $query->id)
            ->whereHas('feed', fn ($feeds) => $feeds->where('source', 'b2b_center'))->pluck('source_feed_id');
        if (in_array($query->status, [QueryStatus::Paused, QueryStatus::Frozen], true)) {
            foreach ($oldIds as $id) {
                if (! SourceFeedSearchQuery::query()->where('source_feed_id', $id)
                    ->whereHas('searchQuery', fn ($queries) => $queries->where('status', QueryStatus::Active))->exists()) {
                    SourceFeed::query()->whereKey($id)->update(['status' => 'paused']);
                }
            }

            return SourceFeed::query()->whereKey($oldIds)->first();
        }
        SourceFeedSearchQuery::query()->where('search_query_id', $query->id)->whereIn('source_feed_id', $oldIds)->delete();
        $feed = null;
        $base = config('tender.b2b_center.catalog_url');
        $phrase = trim(implode(' ', $query->keywords ?? []));
        if (config('tender.b2b_center.enabled') && $query->status === QueryStatus::Active
            && is_string($base) && app(B2bCenterFeedService::class)->isOfficialCatalogUrl($base)
            && $phrase !== '' && mb_strlen($phrase) <= 255) {
            $url = $base.'?'.http_build_query(['f_keyword' => $phrase, 'searching' => 1]);
            $feed = SourceFeed::query()->firstOrCreate(['url_hash' => hash('sha256', 'b2b_center:'.$url)], [
                'source' => 'b2b_center', 'canonical_url' => $url, 'status' => 'active',
                'poll_interval_seconds' => max(300, (int) config('tender.b2b_center.poll_interval_seconds', 3600)),
            ]);
            $feed->forceFill(['status' => 'active'])->save();
            SourceFeedSearchQuery::query()->firstOrCreate(['source_feed_id' => $feed->id, 'search_query_id' => $query->id]);
        }
        foreach ($oldIds as $id) {
            if (! SourceFeedSearchQuery::query()->where('source_feed_id', $id)
                ->whereHas('searchQuery', fn ($queries) => $queries->where('status', QueryStatus::Active))->exists()) {
                SourceFeed::query()->whereKey($id)->update(['status' => 'paused']);
            }
        }

        return $feed;
    }
}
