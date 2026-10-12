<?php

namespace App\Console\Commands;

use App\Services\EtpRegionPollingDispatcher;
use Illuminate\Console\Command;

final class DispatchDueEtpRegionFeed extends Command
{
    protected $signature = 'tenders:dispatch-etp-region';

    protected $description = 'Queue a due ETP Region public procurement search poll';

    public function handle(EtpRegionPollingDispatcher $dispatcher): int
    {
        $this->line($dispatcher->dispatchDueFeed() ? 'ETP Region poll queued.' : 'No ETP Region poll due.');

        return self::SUCCESS;
    }
}
