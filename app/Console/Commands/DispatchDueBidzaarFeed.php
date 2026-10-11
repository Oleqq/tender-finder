<?php

namespace App\Console\Commands;

use App\Services\BidzaarPollingDispatcher;
use Illuminate\Console\Command;

final class DispatchDueBidzaarFeed extends Command
{
    protected $signature = 'tenders:dispatch-bidzaar';

    protected $description = 'Dispatch the due Bidzaar purchase feed.';

    public function handle(BidzaarPollingDispatcher $dispatcher): int
    {
        if ($dispatcher->dispatchDueFeed()) {
            $this->components->info('Bidzaar feed dispatched.');
        }

        return self::SUCCESS;
    }
}
