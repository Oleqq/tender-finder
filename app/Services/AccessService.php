<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\User;

class AccessService
{
    public function snapshotFor(User $user): AccessSnapshot
    {
        /** @var Entitlement|null $entitlement */
        $entitlement = Entitlement::query()
            ->with(['plan', 'subscription'])
            ->where('user_id', $user->id)
            ->where('code', 'active_queries')
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('ends_at')
            ->first();

        if ($entitlement !== null) {
            $state = $entitlement->subscription?->source === SubscriptionSource::Trial
                ? AccessState::Trialing
                : AccessState::Active;

            return AccessSnapshot::fromEntitlement($entitlement, $state);
        }

        $hasExpiredAccess = $user->trial_used_at !== null || Entitlement::query()
            ->where('user_id', $user->id)
            ->where('code', 'active_queries')
            ->exists();

        return new AccessSnapshot($hasExpiredAccess ? AccessState::Expired : AccessState::Preview, null, null, null);
    }

    public function activeQueryLimitFor(User $user): ?int
    {
        return $this->snapshotFor($user)->activeQueryLimit;
    }
}
