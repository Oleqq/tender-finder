<?php

namespace App\Console\Commands;

use App\Services\EtpGpbPollingDispatcher;
use Illuminate\Console\Command;

final class DispatchDueEtpGpbFeed extends Command
{
    protected $signature = 'tenders:dispatch-etp-gpb';

    protected $description = 'Dispatch the due official ETP GPB tender RSS feed.';

    public function handle(EtpGpbPollingDispatcher $dispatcher): int
    {
        $this->components->info($dispatcher->dispatchDueFeed()
            ? 'ETP GPB tender RSS dispatched.'
            : 'ETP GPB processing is disabled or the feed is not due.');

        return self::SUCCESS;
    }
}
