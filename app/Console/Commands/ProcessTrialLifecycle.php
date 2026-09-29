<?php

namespace App\Console\Commands;

use App\Services\TrialLifecycleService;
use Illuminate\Console\Command;

class ProcessTrialLifecycle extends Command
{
    protected $signature = 'trials:process-lifecycle';

    protected $description = 'Queue trial reminders and expire ended trial or support-granted access.';

    public function handle(TrialLifecycleService $trials): int
    {
        $trials->processDue();

        return self::SUCCESS;
    }
}
