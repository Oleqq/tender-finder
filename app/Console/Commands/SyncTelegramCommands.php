<?php

namespace App\Console\Commands;

use App\Services\TelegramBotClient;
use Illuminate\Console\Command;

class SyncTelegramCommands extends Command
{
    protected $signature = 'telegram:sync-commands';

    protected $description = 'Publish the TenderFinder command menu to the configured Telegram bot';

    public function handle(TelegramBotClient $bot): int
    {
        $bot->syncCommands();
        $this->info('Меню команд Telegram обновлено.');

        return self::SUCCESS;
    }
}
