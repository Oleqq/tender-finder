<?php

namespace App\Services;

use App\Jobs\PollSberAstFeed;
use App\Models\SourceFeed;
use Illuminate\Support\Facades\Cache;

final class SberAstPollingDispatcher
{
    public function __construct(private readonly SberAstFeedService $feeds) {}

    public function dispatchOneDueFeed(): bool
    {
        if (! config('tender.sber_ast.enabled')) {
            return false;
        }

        $lock = Cache::lock('sber-ast-poll-dispatch', 5);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->feeds->configuredFeeds();
            /** @var SourceFeed|null $feed */
            $feed = SourceFeed::query()
                ->where('source', 'sber_ast')
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
                ->orderBy('next_poll_at')
                ->first();

            if ($feed === null) {
                return false;
            }

            $feed->forceFill([
                'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
                'last_attempt_at' => now(),
            ])->save();
            PollSberAstFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
