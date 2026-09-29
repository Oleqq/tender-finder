<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrialLifecycleService
{
    public function __construct(private readonly AccessFreezeService $freeze) {}

    public function processDue(): void
    {
        $now = now();

        Entitlement::query()
            ->where('code', 'active_queries')
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now->copy()->addDay())
            ->orderBy('id')
            ->lazyById()
            ->each(fn (Entitlement $entitlement) => $this->processEntitlement($entitlement->id, $now));
    }

    private function processEntitlement(int $entitlementId, Carbon $now): void
    {
        DB::transaction(function () use ($entitlementId, $now): void {
            /** @var Entitlement|null $entitlement */
            $entitlement = Entitlement::query()
                ->with('subscription')
                ->lockForUpdate()
                ->find($entitlementId);

            if (
                $entitlement === null
                || $entitlement->status !== SubscriptionStatus::Active
                || $entitlement->ends_at === null
                || ! in_array($entitlement->subscription?->source, [SubscriptionSource::Trial, SubscriptionSource::AdminGrant], true)
            ) {
                return;
            }

            if ($entitlement->ends_at->lte($now)) {
                $this->expire($entitlement, $now);

                return;
            }

            if ($entitlement->subscription->source === SubscriptionSource::AdminGrant) {
                return;
            }

            $hoursRemaining = $entitlement->ends_at->lte((clone $now)->modify('+3 hours')) ? 3 : 24;
            $this->queueReminder($entitlement, $hoursRemaining, $now);
        });
    }

    private function expire(Entitlement $entitlement, Carbon $now): void
    {
        $entitlement->forceFill(['status' => SubscriptionStatus::Expired])->save();

        if ($entitlement->subscription?->status === SubscriptionStatus::Active) {
            $entitlement->subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        }

        $user = $entitlement->user()->firstOrFail();

        $this->freeze->freezeIfInactive($user, $now);
    }

    private function queueReminder(Entitlement $entitlement, int $hoursRemaining, Carbon $now): void
    {
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => "trial-reminder:{$entitlement->id}:{$hoursRemaining}h"],
            [
                'user_id' => $entitlement->user_id,
                'type' => "trial_ending_{$hoursRemaining}h",
                'status' => NotificationStatus::Queued,
                'payload' => ['hours_remaining' => $hoursRemaining],
                'scheduled_at' => $now,
            ],
        );

        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }
    }
}
