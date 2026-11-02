<?php

namespace App\Console\Commands;

use App\Services\TelegramBotClient;
use Illuminate\Console\Command;

class EnableTelegramConsentCallbacks extends Command
{
    protected $signature = 'telegram:enable-consent-callbacks';

    protected $description = 'Allow consent button callbacks while preserving the existing webhook settings';

    public function handle(TelegramBotClient $bot): int
    {
        $bot->enableConsentCallbacks();
        $this->info('Приём callback_query включён.');

        return self::SUCCESS;
    }
}
