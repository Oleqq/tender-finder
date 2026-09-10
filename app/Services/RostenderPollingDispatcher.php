<?php

namespace App\Services;

use App\Jobs\PollRostenderTemplate;
use App\Models\SourceFeed;
use Illuminate\Support\Facades\Cache;

class RostenderPollingDispatcher
{
    public function __construct(private readonly RostenderAccessGate $gate) {}

    public function dispatchOneDueFeed(): bool
    {
        if (! $this->gate->allowsDataProcessing()) {
            return false;
        }

        $lock = Cache::lock('rostender-poll-dispatch', 5);

        if (! $lock->get()) {
            return false;
        }

        try {
            /** @var SourceFeed|null $feed */
            $feed = SourceFeed::query()
                ->where('source', 'rostender')
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
            PollRostenderTemplate::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
