<?php

namespace App\Services;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TrialService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly PlanCatalog $plans,
        private readonly AccessService $access,
    ) {}

    public function start(User $user): AccessSnapshot
    {
        return DB::transaction(function () use ($user): AccessSnapshot {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->trial_used_at !== null) {
                throw new TrialAlreadyUsedException;
            }

            if (! $this->consents->hasCurrentRequiredConsents($lockedUser)) {
                throw new TrialConsentRequiredException;
            }

            $startsAt = now();
            $endsAt = $startsAt->copy()->addHours(max(1, (int) config('tender.access.trial_hours', 72)));
            $plan = $this->plans->basic();

            $subscription = Subscription::query()->create([
                'user_id' => $lockedUser->id,
                'plan_id' => $plan->id,
                'source' => SubscriptionSource::Trial,
                'status' => SubscriptionStatus::Active,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            Entitlement::query()->create([
                'user_id' => $lockedUser->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'code' => 'active_queries',
                'status' => SubscriptionStatus::Active,
                'value' => (int) config('tender.access.basic_active_query_limit', 3),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'metadata' => ['source' => SubscriptionSource::Trial->value],
            ]);

            $lockedUser->forceFill(['trial_used_at' => $startsAt])->save();

            return $this->access->snapshotFor($lockedUser);
        });
    }
}
