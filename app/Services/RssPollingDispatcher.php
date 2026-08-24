<?php

namespace App\Services;

use App\Jobs\PollEisRssFeed;
use App\Models\SourceFeed;
use Illuminate\Support\Facades\Cache;

class RssPollingDispatcher
{
    public function dispatchOneDueFeed(): bool
    {
        if (! config('tender.rss.live_polling_enabled', false)) {
            return false;
        }

        $lock = Cache::lock('rss-poll-dispatch', 5);

        if (! $lock->get()) {
            return false;
        }

        try {
            $lastRequestAt = Cache::get('rss-last-request-at');
            $minimumInterval = max(1, (int) config('tender.rss.global_min_interval_milliseconds', 1500));

            if (is_float($lastRequestAt) && (microtime(true) - $lastRequestAt) * 1000 < $minimumInterval) {
                return false;
            }

            /** @var SourceFeed|null $feed */
            $feed = SourceFeed::query()
                ->where('status', 'active')
                ->where(function ($query): void {
                    $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now());
                })
                ->orderBy('next_poll_at')
                ->first();

            if ($feed === null) {
                return false;
            }

            $feed->forceFill([
                'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
                'last_attempt_at' => now(),
            ])->save();
            Cache::put('rss-last-request-at', microtime(true), 60);
            PollEisRssFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
