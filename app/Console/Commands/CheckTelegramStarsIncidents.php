<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\TelegramUpdate;
use App\Services\BillingIncidentAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CheckTelegramStarsIncidents extends Command
{
    protected $signature = 'billing:check-stars-incidents {--test-delivery : Send a test alert without reading payments}';

    protected $description = 'Alert administrators about stalled Stars updates and paid subscriptions without access.';

    public function handle(BillingIncidentAlerts $alerts): int
    {
        if ($this->option('test-delivery')) {
            $delivered = $alerts->notify(
                'delivery-test:'.Str::uuid(),
                'Tender Finder: проверка доставки платёжных оповещений. Платёж не создавался.',
            );
            if (! $delivered) {
                $this->error('Test alert could not be delivered to every configured administrator. Check billing.alert logs.');

                return self::FAILURE;
            }

            $this->info('Test alert delivered to every configured administrator.');

            return self::SUCCESS;
        }

        $now = now();
        $stalled = TelegramUpdate::query()
            ->whereIn('type', ['successful_payment', 'refunded_payment', 'subscription'])
            ->whereIn('status', ['received', 'failed'])
            ->where('received_at', '<=', $now->copy()->subMinutes(5));
        $stalledCount = (clone $stalled)->count();
        if ($stalledCount > 0) {
            $updateIds = (clone $stalled)->orderBy('id')->limit(10)->pluck('telegram_update_id')->all();
            $alerts->notify(
                'stalled-updates:'.$stalledCount.':'.implode(',', $updateIds),
                "⚠️ Tender Finder: не обработаны события оплаты Stars ({$stalledCount}). "
                    .'Telegram update ID: '.implode(', ', $updateIds).'. Проверьте очередь и telegram_updates.',
            );
        }

        $uncovered = Payment::query()
            ->where('provider', 'telegram_stars')
            ->where('status', PaymentStatus::Paid)
            ->where(function ($query) use ($now): void {
                $query->whereNull('paid_at')->orWhere('paid_at', '<=', $now->copy()->subMinutes(5));
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('period_ends_at')->orWhere('period_ends_at', '>', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('period_ends_at')
                    ->orWhereDoesntHave('subscription', function ($subscription) use ($now): void {
                        $subscription->where('source', SubscriptionSource::TelegramStars)
                            ->where('status', SubscriptionStatus::Active)
                            ->whereColumn('subscriptions.user_id', 'payments.user_id')
                            ->whereColumn('subscriptions.plan_id', 'payments.plan_id')
                            ->where('ends_at', '>', $now)
                            ->whereColumn('subscriptions.ends_at', '>=', 'payments.period_ends_at')
                            ->whereHas('entitlements', function ($entitlement) use ($now): void {
                                $entitlement->where('code', 'active_queries')
                                    ->where('status', SubscriptionStatus::Active)
                                    ->whereColumn('entitlements.user_id', 'subscriptions.user_id')
                                    ->whereColumn('entitlements.plan_id', 'subscriptions.plan_id')
                                    ->where('value', '>', 0)
                                    ->where('starts_at', '<=', $now)
                                    ->where('ends_at', '>', $now)
                                    ->whereColumn('entitlements.ends_at', '>=', 'payments.period_ends_at');
                            });
                    });
            });
        $uncoveredCount = (clone $uncovered)->count();
        if ($uncoveredCount > 0) {
            $paymentIds = (clone $uncovered)->orderBy('id')->limit(10)->pluck('id')->all();
            $alerts->notify(
                'paid-without-access:'.$uncoveredCount.':'.implode(',', $paymentIds),
                "⚠️ Tender Finder: оплаченные Stars-периоды без доступа ({$uncoveredCount}). "
                    .'Платежи #'.implode(', #', $paymentIds).'. Проверьте платежи, подписки и права доступа.',
            );
        }

        $this->info("Stalled payment updates: {$stalledCount}; paid periods without access: {$uncoveredCount}.");

        return self::SUCCESS;
    }
}
