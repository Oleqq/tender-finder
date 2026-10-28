<?php

namespace App\Services;

use App\Enums\QueryStatus;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;

final class FeedAccessService
{
    public function mayPoll(SourceFeed $feed): bool
    {
        $links = $feed->source === 'rostender'
            ? RostenderFeedSearchQuery::query()->where('source_feed_id', $feed->id)->with('searchQuery.user')->get()
            : SourceFeedSearchQuery::query()->where('source_feed_id', $feed->id)->with('searchQuery.user')->get();
        // Unscoped public catalogs can still populate the shared cache.
        if ($links->isEmpty()) {
            return true;
        }
        foreach ($links as $link) {
            $query = $link->searchQuery;
            if ($query->status === QueryStatus::Active && app(AccessService::class)->hasActiveAccess($query->user)) {
                return true;
            }
            app(AccessFreezeService::class)->freezeIfInactive($query->user, now());
        }
        $feed->update(['status' => 'paused', 'next_poll_at' => null]);

        return false;
    }
}
