<?php

namespace App\Console\Commands;

use App\Services\LocalMvpOperatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class MakeRemoteMvpOperatorLink extends Command
{
    protected $signature = 'mvp:operator-link {--minutes=30 : Lifetime from 1 to 60 minutes}';

    protected $description = 'Create an expiring signed link for supervised remote MVP operator testing';

    public function handle(LocalMvpOperatorService $operator): int
    {
        if (! $operator->isRemoteEnabled()) {
            $this->error('Remote MVP operator access is disabled. Set REMOTE_MVP_OPERATOR_ENABLED=true on the web service and redeploy it first.');

            return self::FAILURE;
        }

        $minutes = max(1, min(60, (int) $this->option('minutes')));
        $url = URL::temporarySignedRoute(
            'mvp.remote-operator.session',
            now()->addMinutes($minutes),
        );

        $this->warn("The following URL logs a browser into the MVP test operator for {$minutes} minutes. Do not share it.");
        $this->line($url);

        return self::SUCCESS;
    }
}
