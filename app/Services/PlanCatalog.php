<?php

namespace App\Services;

use App\Models\Plan;

class PlanCatalog
{
    public const BASIC_CODE = 'basic';

    public const PRO_CODE = 'pro';

    public function basic(): Plan
    {
        /** @var Plan $plan */
        $plan = Plan::query()->firstOrCreate(
            ['code' => self::BASIC_CODE],
            [
                'name' => 'Basic',
                'is_active' => true,
                'limits' => [
                    'active_queries' => (int) config('tender.access.basic_active_query_limit', 3),
                ],
            ],
        );

        return $plan;
    }

    public function pro(): Plan
    {
        /** @var Plan $plan */
        $plan = Plan::query()->firstOrCreate(
            ['code' => self::PRO_CODE],
            [
                'name' => 'Про',
                'is_active' => true,
                'limits' => [
                    'active_queries' => (int) config('tender.access.pro_active_query_limit', 10),
                    'ai_scoring' => true,
                ],
            ],
        );

        return $plan;
    }

    public function byCode(string $code): ?Plan
    {
        return match ($code) {
            self::BASIC_CODE => $this->basic(),
            self::PRO_CODE => $this->pro(),
            default => null,
        };
    }
}
