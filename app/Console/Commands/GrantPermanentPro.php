<?php

namespace App\Console\Commands;

use App\Services\PermanentProGrantService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class GrantPermanentPro extends Command
{
    protected $signature = 'access:grant-permanent-pro
        {telegram-id : Verified Telegram user ID}
        {--developer-mode : Show Developer Mode on the Pro access}
        {--reason= : Required audit reason}';

    protected $description = 'Grant audited, non-expiring Pro access and notify the user in Telegram';

    public function handle(PermanentProGrantService $grants): int
    {
        try {
            $entitlement = $grants->grant(
                (string) $this->argument('telegram-id'),
                (bool) $this->option('developer-mode'),
                trim((string) $this->option('reason')),
            );
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->first() ?? 'Доступ не изменён.');

            return self::FAILURE;
        }

        $this->info("Pro access granted; entitlement ID {$entitlement->id}. Telegram notification queued.");

        return self::SUCCESS;
    }
}
