<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

final class CreateDemoReviewerLink extends Command
{
    protected $signature = 'demo:reviewer-link {--hours=24}';

    protected $description = 'Create a private, expiring browser demo invitation (no real payments)';

    public function handle(): int
    {
        if (! config('tender.payments.demo_enabled')) {
            $this->error('DEMO_CHECKOUT_ENABLED must be explicitly enabled.');

            return self::FAILURE;
        }
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 1 || $hours > 168) {
            $this->error('Choose 1–168 hours.');

            return self::FAILURE;
        }
        $this->line(URL::temporarySignedRoute('demo.reviewer', now()->addHours($hours)));

        return self::SUCCESS;
    }
}
