<?php

namespace App\Services;

use App\Enums\AccessState;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\User;

final class DeveloperPlanService
{
    public const CODES = ['basic', 'pro', 'ultra'];

    public function __construct(private readonly LocalMvpOperatorService $operator, private readonly PlanCatalog $plans) {}

    public function allowed(User $user): bool
    {
        return $this->operator->canUseWorkspace($user) || $this->developerEntitlement($user) !== null;
    }

    public function overrideFor(User $user): ?AccessSnapshot
    {
        $code = $user->developer_plan_code;
        if (! in_array($code, self::CODES, true)) {
            return null;
        }
        $owner = $this->operator->canUseWorkspace($user);
        $grant = $owner ? null : $this->developerEntitlement($user);
        if (! $owner && $grant === null) {
            return null;
        }

        return new AccessSnapshot(AccessState::Active, $code, $this->limit($code), $grant?->ends_at?->toAtomString(), 'developer');
    }

    /** @return array<string, mixed>|null */
    public function contextFor(User $user): ?array
    {
        if (! $this->allowed($user)) {
            return null;
        }

        return [
            'selected_plan' => in_array($user->developer_plan_code, self::CODES, true) ? $user->developer_plan_code : null,
            'plans' => array_map(fn (string $code): array => [
                'code' => $code,
                'name' => match ($code) {
                    'pro' => 'Pro', 'ultra' => 'Ultra', default => 'Basic'
                },
                'active_query_limit' => $this->limit($code),
                'analytics' => match ($code) {
                    'pro' => 'Подробная', 'ultra' => 'Полная', default => 'Краткая'
                },
            ], self::CODES),
        ];
    }

    private function limit(string $code): int
    {
        return max(0, (int) ($this->plans->byCode($code)?->limits['active_queries'] ?? 0));
    }

    private function developerEntitlement(User $user): ?Entitlement
    {
        return Entitlement::query()->where('user_id', $user->id)->where('code', 'active_queries')
            ->where('status', SubscriptionStatus::Active)->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where('metadata->developer_mode', true)
            ->whereHas('subscription', fn ($q) => $q->where('source', SubscriptionSource::AdminGrant)->where('status', SubscriptionStatus::Active))
            ->orderByDesc('id')->first();
    }
}
