<?php

namespace App\Services;

use App\Jobs\PollB2bCenterFeed;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;
use Illuminate\Support\Facades\Cache;

final class B2bCenterPollingDispatcher
{
    public function __construct(private readonly B2bCenterFeedService $feeds) {}

    public function dispatchDueFeed(?int $feedId = null): bool
    {
        if (! config('tender.b2b_center.enabled')) {
            return false;
        }

        $lock = Cache::lock('b2b-center-poll-dispatch', 5);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->feeds->configuredFeed();
            $feed = SourceFeed::query()->where('source', 'b2b_center')->where('status', 'active')
                ->where(fn ($feeds) => $feeds->where('canonical_url', config('tender.b2b_center.catalog_url'))
                    ->orWhereIn('id', SourceFeedSearchQuery::query()
                        ->whereHas('searchQuery', fn ($queries) => $queries->where('status', 'active'))->select('source_feed_id')))
                ->when($feedId !== null, fn ($feeds) => $feeds->whereKey($feedId))
                ->where(fn ($feeds) => $feeds->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
                ->orderBy('next_poll_at')->first();

            if ($feed === null || ($feed->next_poll_at !== null && $feed->next_poll_at->isFuture())) {
                return false;
            }

            $feed->forceFill([
                'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
                'last_attempt_at' => now(),
            ])->save();
            PollB2bCenterFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
