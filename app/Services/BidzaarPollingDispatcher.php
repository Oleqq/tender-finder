<?php

namespace App\Services;

use App\Jobs\PollBidzaarFeed;
use Illuminate\Support\Facades\Cache;

final class BidzaarPollingDispatcher
{
    public function __construct(private readonly BidzaarFeedService $feeds) {}

    public function dispatchDueFeed(): bool
    {
        $lock = Cache::lock('bidzaar-poll-dispatch', 5);
        if (! $lock->get()) {
            return false;
        }

        try {
            $feed = $this->feeds->configuredFeed();
            if ($feed === null || ($feed->next_poll_at !== null && $feed->next_poll_at->isFuture())) {
                return false;
            }

            $feed->forceFill([
                'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
                'last_attempt_at' => now(),
            ])->save();
            PollBidzaarFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
