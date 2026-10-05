<?php

namespace App\Console\Commands;

use App\Services\PlatformCatalogFeedService;
use App\Services\PlatformCatalogPollingDispatcher;
use Illuminate\Console\Command;

final class DispatchDuePlatformCatalogFeed extends Command
{
    protected $signature = 'tenders:dispatch-platform-catalog';

    protected $description = 'Dispatch due public platform catalog polls.';

    public function handle(PlatformCatalogPollingDispatcher $dispatcher): int
    {
        foreach (array_keys(PlatformCatalogFeedService::URLS) as $source) {
            if ($dispatcher->dispatchDueFeed($source)) {
                $this->components->info($source.' catalog dispatched.');
            }
        }

        return self::SUCCESS;
    }
}
