<?php

namespace App\Services;

use App\Jobs\PollPlatformCatalogFeed;
use Illuminate\Support\Facades\Cache;

final class PlatformCatalogPollingDispatcher
{
    public function __construct(private readonly PlatformCatalogFeedService $feeds) {}

    public function dispatchDueFeed(string $source): bool
    {
        if (! isset(PlatformCatalogFeedService::URLS[$source]) || ! config("tender.platform_catalog.{$source}.enabled")) {
            return false;
        }

        $lock = Cache::lock('platform-catalog-'.$source.'-poll-dispatch', 5);
        if (! $lock->get()) {
            return false;
        }

        try {
            $feed = $this->feeds->configuredFeed($source);
            if ($feed === null || ($feed->next_poll_at !== null && $feed->next_poll_at->isFuture())) {
                return false;
            }

            $feed->forceFill([
                'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
                'last_attempt_at' => now(),
            ])->save();
            PollPlatformCatalogFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
