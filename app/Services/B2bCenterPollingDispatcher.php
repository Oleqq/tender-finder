<?php

namespace App\Services;

use App\Jobs\PollB2bCenterFeed;
use Illuminate\Support\Facades\Cache;

final class B2bCenterPollingDispatcher
{
    public function __construct(private readonly B2bCenterFeedService $feeds) {}

    public function dispatchDueFeed(): bool
    {
        if (! config('tender.b2b_center.enabled')) {
            return false;
        }

        $lock = Cache::lock('b2b-center-poll-dispatch', 5);

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
            PollB2bCenterFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
