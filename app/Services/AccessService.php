<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\User;

class AccessService
{
    public function __construct(
        private readonly LocalMvpOperatorService $localMvpOperator,
        private readonly LocalMvpFullAccessService $localMvpFullAccess,
    ) {}

    public function snapshotFor(User $user): AccessSnapshot
    {
        if ($this->localMvpFullAccess->isEnabled()) {
            return new AccessSnapshot(
                AccessState::Active,
                'local_mvp_full_access',
                $this->localMvpFullAccess->activeQueryLimit(),
                null,
            );
        }

        if ($this->localMvpOperator->isOperator($user)) {
            return new AccessSnapshot(
                AccessState::Active,
                'local_mvp_operator',
                $this->localMvpOperator->activeQueryLimit(),
                null,
            );
        }

        if ($user->role === UserRole::SuperAdmin) {
            return new AccessSnapshot(
                AccessState::Active,
                'pro',
                null,
                null,
                'owner',
            );
        }

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
            ->orderByDesc('value')
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END DESC')
            ->orderByDesc('ends_at')
            ->first();

        if ($entitlement !== null) {
            $state = $entitlement->subscription?->source === SubscriptionSource::Trial
                ? AccessState::Trialing
                : AccessState::Active;

            return AccessSnapshot::fromEntitlement($entitlement, $state);
        }

        $lastEntitlement = Entitlement::query()
            ->where('user_id', $user->id)
            ->where('code', 'active_queries')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->orderByDesc('ends_at')
            ->first();

        $hasExpiredAccess = $user->trial_used_at !== null || $lastEntitlement !== null || Entitlement::query()
            ->where('user_id', $user->id)
            ->where('code', 'active_queries')
            ->whereHas('subscription', fn ($subscription) => $subscription->where('source', SubscriptionSource::TelegramStars))
            ->exists();

        return new AccessSnapshot(
            $hasExpiredAccess ? AccessState::Expired : AccessState::Preview,
            null,
            null,
            $hasExpiredAccess ? $lastEntitlement?->ends_at?->toAtomString() : null,
        );
    }

    public function activeQueryLimitFor(User $user): ?int
    {
        return $this->snapshotFor($user)->activeQueryLimit;
    }

    public function hasActiveAccess(User $user): bool
    {
        return in_array($this->snapshotFor($user)->state, [AccessState::Trialing, AccessState::Active], true);
    }
}
