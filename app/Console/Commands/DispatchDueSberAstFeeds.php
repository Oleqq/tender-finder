<?php

namespace App\Console\Commands;

use App\Services\SberAstPollingDispatcher;
use Illuminate\Console\Command;

final class DispatchDueSberAstFeeds extends Command
{
    protected $signature = 'tenders:dispatch-sber-ast';

    protected $description = 'Dispatch one due public Sberbank-AST registry feed.';

    public function handle(SberAstPollingDispatcher $dispatcher): int
    {
        $this->components->info($dispatcher->dispatchOneDueFeed()
            ? 'One Sberbank-AST registry dispatched.'
            : 'Sberbank-AST processing is disabled or no registry is due.');

        return self::SUCCESS;
    }
}
