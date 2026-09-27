<?php

namespace App\Console\Commands;

use App\Services\WorkspaceRuPollingDispatcher;
use Illuminate\Console\Command;

final class DispatchDueWorkspaceRuFeed extends Command
{
    protected $signature = 'tenders:dispatch-workspace-ru';

    protected $description = 'Dispatch the due official Workspace.ru tender RSS feed.';

    public function handle(WorkspaceRuPollingDispatcher $dispatcher): int
    {
        $this->components->info($dispatcher->dispatchDueFeed()
            ? 'Workspace.ru tender RSS dispatched.'
            : 'Workspace.ru processing is disabled or the feed is not due.');

        return self::SUCCESS;
    }
}
