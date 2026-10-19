<?php

namespace App\Console\Commands;

use App\Enums\AccessState;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccessFreezeService;
use App\Services\AccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetTrialPreview extends Command
{
    protected $signature = 'access:reset-trial-preview {telegram_id} {--apply : Apply the reset after a verified backup}';

    protected $description = 'Return one verified Telegram user to the first-start preview without deleting access history';

    public function handle(AccessService $access, AccessFreezeService $freeze): int
    {
        $telegramId = (string) $this->argument('telegram_id');
        if (preg_match('/^\d+$/', $telegramId) !== 1) {
            $this->error('A numeric Telegram user ID is required.');

            return self::FAILURE;
        }

        $user = User::query()->where('telegram_id', $telegramId)->first();
        if ($user === null) {
            $this->error('Telegram user does not exist.');

            return self::FAILURE;
        }

        $configuredIds = preg_split('/[\s,;]+/', (string) config('tender.telegram.superadmin_ids')) ?: [];
        $configuredIds[] = (string) config('tender.telegram.owner_id');
        $stillConfiguredOwner = in_array($telegramId, $configuredIds, true);
        $hasBillingHistory = Payment::query()->where('user_id', $user->id)->exists()
            || Subscription::query()->where('user_id', $user->id)
                ->where('source', SubscriptionSource::TelegramStars)->exists();
        $this->line('Current role: '.$user->role->value);
        $this->line('Current access: '.$access->snapshotFor($user)->state->value);
        $this->line('Trial previously started: '.($user->trial_used_at === null ? 'no' : 'yes'));
        $this->line('Active entitlements: '.Entitlement::query()->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::Active)->count());
        $this->line('Still configured as owner: '.($stillConfiguredOwner ? 'yes' : 'no'));
        $this->line('Billing history present: '.($hasBillingHistory ? 'yes' : 'no'));

        if (! $this->option('apply')) {
            if ($hasBillingHistory) {
                $this->error('Billing history requires a separate review before this reset.');

                return self::FAILURE;
            }
            $this->info('Dry run only. Use --apply after a verified PostgreSQL backup.');

            return self::SUCCESS;
        }

        if ($stillConfiguredOwner || $hasBillingHistory) {
            $this->error('Remove the owner ID and review any billing history before applying the reset.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($telegramId): void {
            $user = User::query()->where('telegram_id', $telegramId)->lockForUpdate()->firstOrFail();
            if (Payment::query()->where('user_id', $user->id)->exists()
                || Subscription::query()->where('user_id', $user->id)
                    ->where('source', SubscriptionSource::TelegramStars)->exists()) {
                throw new \RuntimeException('Billing history appeared while resetting access.');
            }
            $now = now();

            Entitlement::query()->where('user_id', $user->id)
                ->where('status', SubscriptionStatus::Active)
                ->update(['status' => SubscriptionStatus::Expired, 'ends_at' => $now]);
            Subscription::query()->where('user_id', $user->id)
                ->whereIn('source', [SubscriptionSource::Trial, SubscriptionSource::AdminGrant])
                ->where('status', SubscriptionStatus::Active)
                ->update(['status' => SubscriptionStatus::Expired, 'ends_at' => $now]);

            $user->forceFill(['role' => UserRole::Subscriber, 'trial_used_at' => null])->save();
        });

        $user->refresh();
        $freeze->freezeIfInactive($user, now());
        $state = $access->snapshotFor($user)->state;
        $this->line('Updated role: '.$user->role->value);
        $this->line('Updated access: '.$state->value);

        return $state === AccessState::Preview ? self::SUCCESS : self::FAILURE;
    }
}
