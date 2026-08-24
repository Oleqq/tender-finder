<?php

namespace App\Console\Commands;

use App\Services\RssPollingDispatcher;
use Illuminate\Console\Command;

class DispatchDueRssFeeds extends Command
{
    protected $signature = 'tenders:dispatch-rss';

    protected $description = 'Dispatch at most one due EIS RSS feed while live polling is enabled.';

    public function handle(RssPollingDispatcher $dispatcher): int
    {
        if (! config('tender.rss.live_polling_enabled', false)) {
            $this->components->info('RSS live polling is disabled; fixtures remain available for tests.');

            return self::SUCCESS;
        }

        $this->components->info($dispatcher->dispatchOneDueFeed() ? 'One RSS feed dispatched.' : 'No RSS feed due.');

        return self::SUCCESS;
    }
}
