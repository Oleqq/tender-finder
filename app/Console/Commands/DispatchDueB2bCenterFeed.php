<?php

namespace App\Console\Commands;

use App\Services\B2bCenterPollingDispatcher;
use Illuminate\Console\Command;

final class DispatchDueB2bCenterFeed extends Command
{
    protected $signature = 'tenders:dispatch-b2b-center';

    protected $description = 'Dispatch the due public B2B-Center tender catalog.';

    public function handle(B2bCenterPollingDispatcher $dispatcher): int
    {
        $this->components->info($dispatcher->dispatchDueFeed()
            ? 'B2B-Center tender catalog dispatched.'
            : 'B2B-Center processing is disabled or the catalog is not due.');

        return self::SUCCESS;
    }
}
