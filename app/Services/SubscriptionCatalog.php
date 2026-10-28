<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class SubscriptionCatalog
{
    public const OFFERS = [
        'basic' => ['name' => 'Basic', 'limit' => 3, 'prices' => [1 => 34900, 3 => 98900, 12 => 376900], 'description' => 'Поиск и краткая аналитика тендера'],
        'pro' => ['name' => 'Pro', 'limit' => 10, 'prices' => [1 => 59900, 3 => 169900, 12 => 646900], 'description' => 'Мой прайс, предложения из блоков и подробная аналитика'],
        'ultra' => ['name' => 'Ultra', 'limit' => 30, 'prices' => [1 => 89900, 3 => 255900, 12 => 970900], 'description' => 'Всё из Pro, контроль прибыли и объединение закупок'],
    ];

    public function endAt(Carbon $start, int $months): Carbon
    {
        // Moscow calendar, preserve day/time; clamp to the last available day.
        return $start->copy()->setTimezone('Europe/Moscow')->addMonthsNoOverflow($months)->utc();
    }

    /** @return array<string, mixed> */
    public function offer(string $code, int $months): array
    {
        $plan = self::OFFERS[$code] ?? null;
        if ($plan === null || ! isset($plan['prices'][$months])) {
            throw ValidationException::withMessages(['plan_code' => 'Выберите тариф и период из каталога.']);
        }

        return [
            'code' => $code, 'name' => $plan['name'], 'description' => $plan['description'],
            'duration_months' => $months, 'amount_minor' => $plan['prices'][$months],
            'currency' => 'RUB', 'active_query_limit' => $plan['limit'],
            'auto_renew' => false, 'terms_version' => 'demo-2026-10-28', 'terms_url' => '/offer',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $offers = [];
        foreach (array_keys(self::OFFERS) as $code) {
            foreach ([1, 3, 12] as $months) {
                $offers[] = $this->offer($code, $months);
            }
        }

        return $offers;
    }
}
