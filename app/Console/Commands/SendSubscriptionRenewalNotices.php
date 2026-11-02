<?php

namespace App\Console\Commands;

use App\Services\SubscriptionRenewalService;
use Illuminate\Console\Command;

class SendSubscriptionRenewalNotices extends Command
{
    protected $signature = 'subscriptions:send-renewal-notices';

    protected $description = 'Deliver due renewal notices with cancellation links; never charge a payment';

    public function handle(SubscriptionRenewalService $renewals): int
    {
        $this->info('Уведомлений обработано: '.$renewals->processDue());

        return self::SUCCESS;
    }
}
