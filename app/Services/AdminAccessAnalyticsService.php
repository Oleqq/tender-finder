<?php

namespace App\Services;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AdminAccessAnalyticsService
{
    /** @return array{registered: int, preview: int, trialing: int, paid: int, granted: int, expired: int} */
    public function snapshot(): array
    {
        $activeSources = $this->activeSourcesByUser();
        $activeUserIds = array_keys($activeSources);
        $customerUsers = $this->customerUsers();

        return [
            'registered' => (clone $customerUsers)->count(),
            'preview' => (clone $customerUsers)
                ->whereNull('trial_used_at')
                ->whereNotIn('id', $activeUserIds)
                ->count(),
            'trialing' => $this->activeUsersWithSource($activeSources, SubscriptionSource::Trial),
            'paid' => $this->activeUsersWithSource($activeSources, SubscriptionSource::TelegramStars),
            'granted' => $this->activeUsersWithSource($activeSources, SubscriptionSource::AdminGrant),
            'expired' => (clone $customerUsers)
                ->whereNotNull('trial_used_at')
                ->whereNotIn('id', $activeUserIds)
                ->count(),
        ];
    }

    /** @return Builder<User> */
    private function customerUsers(): Builder
    {
        return User::query()
            ->where('role', UserRole::Subscriber)
            ->whereNotNull('telegram_id');
    }

    /** @return array<int, SubscriptionSource|null> */
    private function activeSourcesByUser(): array
    {
        /** @var Collection<int, Entitlement> $entitlements */
        $entitlements = Entitlement::query()
            ->with('subscription:id,source')
            ->where('code', 'active_queries')
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('ends_at')
            ->get(['id', 'user_id', 'subscription_id', 'ends_at']);

        return $entitlements
            ->unique('user_id')
            ->mapWithKeys(fn (Entitlement $entitlement): array => [
                $entitlement->user_id => $entitlement->subscription?->source,
            ])
            ->all();
    }

    /** @param array<int, SubscriptionSource|null> $sources */
    private function activeUsersWithSource(array $sources, SubscriptionSource $source): int
    {
        return count(array_filter(
            $sources,
            fn (?SubscriptionSource $current): bool => $current === $source,
        ));
    }
}
