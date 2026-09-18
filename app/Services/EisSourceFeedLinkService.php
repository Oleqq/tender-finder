<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;

final class EisSourceFeedLinkService
{
    public function attach(SearchQuery $query, SourceFeed $feed): void
    {
        SourceFeedSearchQuery::query()->firstOrCreate([
            'source_feed_id' => $feed->id,
            'search_query_id' => $query->id,
        ]);
    }

    public function detach(SearchQuery $query): void
    {
        SourceFeedSearchQuery::query()->where('search_query_id', $query->id)->delete();
    }
}
