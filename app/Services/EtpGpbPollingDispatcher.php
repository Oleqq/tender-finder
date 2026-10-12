<?php

namespace App\Services;

use App\Jobs\PollEtpGpbFeed;
use Illuminate\Support\Facades\Cache;

final class EtpGpbPollingDispatcher
{
    public function __construct(private readonly EtpGpbFeedService $feeds) {}

    public function dispatchDueFeed(): bool
    {
        if (! config('tender.etp_gpb.enabled')) {
            return false;
        }

        $lock = Cache::lock('etp-gpb-poll-dispatch', 5);
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
            PollEtpGpbFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
