<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RefreshWatchedTenders extends Command
{
    protected $signature = 'tenders:refresh-watched';

    protected $description = 'Refresh selected RosTender cards within the shared API quota';

    public function handle(): int
    {
        \App\Jobs\RefreshWatchedTenders::dispatch();

        return self::SUCCESS;
    }
}
