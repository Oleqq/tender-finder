<?php

namespace App\Services;

use App\Jobs\PollWorkspaceRuFeed;
use Illuminate\Support\Facades\Cache;

final class WorkspaceRuPollingDispatcher
{
    public function __construct(private readonly WorkspaceRuFeedService $feeds) {}

    public function dispatchDueFeed(): bool
    {
        if (! config('tender.workspace_ru.enabled')) {
            return false;
        }

        $lock = Cache::lock('workspace-ru-poll-dispatch', 5);

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
            PollWorkspaceRuFeed::dispatch($feed->id);

            return true;
        } finally {
            $lock->release();
        }
    }
}
