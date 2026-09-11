<?php

namespace App\Console\Commands;

use App\Services\TenderFollowUpService;
use Illuminate\Console\Command;

class SendTenderReminders extends Command
{
    protected $signature = 'notifications:send-tender-reminders';

    protected $description = 'Queue deadline and personal action reminders';

    public function handle(TenderFollowUpService $followUp): int
    {
        $followUp->queueReminders();

        return self::SUCCESS;
    }
}
