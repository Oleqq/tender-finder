<?php

namespace App\Services;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class AdminAccessAnalyticsService
{
    /**
     * @return array{
     *     period: array{key: string, days: int, startsAt: string, generatedAt: string},
     *     audience: array{total: int, newUsers: int, miniAppActive: int},
     *     activation: array{trialsStarted: int, trialsTotal: int, starsStarted: int},
     *     access: array{preview: int, trialing: int, paid: int, granted: int, expired: int},
     *     funnel: array{registered: int, trialed: int, paid: int, trialRate: float, paidRate: float},
     *     series: list<array{date: string, label: string, registrations: int, trials: int, starsStarts: int}>,
     *     commerce: array{state: string, message: string}
     * }
     */
    public function snapshot(?string $requestedPeriod = null): array
    {
        $period = $this->period($requestedPeriod);
        $now = now();
        $periodStart = $now->copy()->startOfDay()->subDays($period['days'] - 1);
        $activeSources = $this->activeSourcesByUser($now);
        $activeUserIds = array_keys($activeSources);
        $customerUsers = $this->customerUsers();
        $registered = (clone $customerUsers)->count();
        $trialsTotal = (clone $customerUsers)->whereNotNull('trial_used_at')->count();
        $paid = $this->activeUsersWithSource($activeSources, SubscriptionSource::TelegramStars);

        return [
            'period' => [
                'key' => $period['key'],
                'days' => $period['days'],
                'startsAt' => $periodStart->toIso8601String(),
                'generatedAt' => $now->toIso8601String(),
            ],
            'audience' => [
                'total' => $registered,
                'newUsers' => (clone $customerUsers)
                    ->whereBetween('created_at', [$periodStart, $now])
                    ->count(),
                'miniAppActive' => (clone $customerUsers)
                    ->whereBetween('last_seen_at', [$periodStart, $now])
                    ->count(),
            ],
            'activation' => [
                'trialsStarted' => (clone $customerUsers)
                    ->whereBetween('trial_used_at', [$periodStart, $now])
                    ->count(),
                'trialsTotal' => $trialsTotal,
                'starsStarted' => $this->starsStartedInPeriod($periodStart, $now),
            ],
            'access' => [
                'preview' => (clone $customerUsers)
                    ->whereNull('trial_used_at')
                    ->whereNotIn('id', $activeUserIds)
                    ->count(),
                'trialing' => $this->activeUsersWithSource($activeSources, SubscriptionSource::Trial),
                'paid' => $paid,
                'granted' => $this->activeUsersWithSource($activeSources, SubscriptionSource::AdminGrant),
                'expired' => (clone $customerUsers)
                    ->whereNotNull('trial_used_at')
                    ->whereNotIn('id', $activeUserIds)
                    ->count(),
            ],
            'funnel' => [
                'registered' => $registered,
                'trialed' => $trialsTotal,
                'paid' => $paid,
                'trialRate' => $this->percentage($trialsTotal, $registered),
                'paidRate' => $this->percentage($paid, $trialsTotal),
            ],
            'series' => $this->dailySeries($periodStart, $now),
            'commerce' => [
                'state' => 'pending',
                'message' => 'Выручка появится после подключения платежей через Telegram Stars.',
            ],
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
    private function activeSourcesByUser(Carbon $now): array
    {
        /** @var Collection<int, Entitlement> $entitlements */
        $entitlements = Entitlement::query()
            ->with('subscription:id,source')
            ->whereIn('user_id', $this->customerUsers()->select('id'))
            ->where('code', 'active_queries')
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
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

    /** @return array{key: string, days: int} */
    private function period(?string $requestedPeriod): array
    {
        return match ($requestedPeriod) {
            '7d' => ['key' => '7d', 'days' => 7],
            '90d' => ['key' => '90d', 'days' => 90],
            default => ['key' => '30d', 'days' => 30],
        };
    }

    private function starsStartedInPeriod(Carbon $periodStart, Carbon $now): int
    {
        return Subscription::query()
            ->whereIn('user_id', $this->customerUsers()->select('id'))
            ->where('source', SubscriptionSource::TelegramStars)
            ->whereBetween('starts_at', [$periodStart, $now])
            ->distinct()
            ->count('user_id');
    }

    /** @return list<array{date: string, label: string, registrations: int, trials: int, starsStarts: int}> */
    private function dailySeries(Carbon $periodStart, Carbon $now): array
    {
        $series = [];

        for ($day = $periodStart->copy(); $day->lte($now); $day->addDay()) {
            $key = $day->toDateString();
            $series[$key] = [
                'date' => $key,
                'label' => $day->format('d.m'),
                'registrations' => 0,
                'trials' => 0,
                'starsStarts' => 0,
            ];
        }

        foreach ((clone $this->customerUsers())
            ->whereBetween('created_at', [$periodStart, $now])
            ->get(['created_at']) as $user) {
            $this->incrementDailyMetric($series, $user->created_at, 'registrations');
        }

        foreach ((clone $this->customerUsers())
            ->whereBetween('trial_used_at', [$periodStart, $now])
            ->get(['trial_used_at']) as $user) {
            $this->incrementDailyMetric($series, $user->trial_used_at, 'trials');
        }

        foreach (Subscription::query()
            ->whereIn('user_id', $this->customerUsers()->select('id'))
            ->where('source', SubscriptionSource::TelegramStars)
            ->whereBetween('starts_at', [$periodStart, $now])
            ->get(['starts_at']) as $subscription) {
            $this->incrementDailyMetric($series, $subscription->starts_at, 'starsStarts');
        }

        return array_values($series);
    }

    /** @param array<string, array{date: string, label: string, registrations: int, trials: int, starsStarts: int}> $series */
    private function incrementDailyMetric(array &$series, CarbonInterface|string|null $date, string $metric): void
    {
        if ($date === null) {
            return;
        }

        $key = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();

        if (! isset($series[$key])) {
            return;
        }

        switch ($metric) {
            case 'registrations':
                $series[$key]['registrations']++;
                break;
            case 'trials':
                $series[$key]['trials']++;
                break;
            case 'starsStarts':
                $series[$key]['starsStarts']++;
                break;
        }
    }

    private function percentage(int $value, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($value / $total) * 100, 1);
    }
}
