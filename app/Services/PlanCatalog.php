<?php

namespace App\Services;

use App\Models\Plan;

class PlanCatalog
{
    public const BASIC_CODE = 'basic';

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
}
