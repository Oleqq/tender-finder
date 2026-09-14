<?php

namespace App\Console\Commands;

use App\Services\TeamWorkflowService;
use Illuminate\Console\Command;

final class ProcessTeamTenderReviews extends Command
{
    protected $signature = 'teams:process-tender-reviews';

    protected $description = 'Assign new team tenders and queue SLA alerts and team digests';

    public function handle(TeamWorkflowService $workflow): int
    {
        $workflow->queueDue();

        return self::SUCCESS;
    }
}
