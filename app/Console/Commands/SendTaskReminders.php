<?php

namespace App\Console\Commands;

use App\Services\TaskReminderService;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    protected $signature = 'notifications:send-task-reminders';

    protected $description = 'Queue opted-in checklist reminders for assignees';

    public function handle(TaskReminderService $reminders): int
    {
        $reminders->queueDue();

        return self::SUCCESS;
    }
}
