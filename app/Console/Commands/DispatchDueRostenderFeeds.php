<?php

namespace App\Console\Commands;

use App\Services\RostenderPollingDispatcher;
use Illuminate\Console\Command;

class DispatchDueRostenderFeeds extends Command
{
    protected $signature = 'tenders:dispatch-rostender';

    protected $description = 'Dispatch at most one due RosTender template while licensed processing is enabled.';

    public function handle(RostenderPollingDispatcher $dispatcher): int
    {
        $this->components->info($dispatcher->dispatchOneDueFeed() ? 'One RosTender template dispatched.' : 'RosTender processing is disabled or no template is due.');

        return self::SUCCESS;
    }
}
