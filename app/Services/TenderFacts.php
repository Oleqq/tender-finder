<?php

namespace App\Services;

use App\Models\Tender;

final class TenderFacts
{
    public static function customer(Tender $tender): ?string
    {
        $metadata = $tender->metadata ?? [];
        $customer = $metadata['customer'] ?? $metadata['rostender']['customer'] ?? null;
        if (is_array($customer)) {
            $customer = $customer['name'] ?? $customer['title'] ?? null;
        }

        return is_string($customer) && trim($customer) !== '' ? trim($customer) : null;
    }

    /** @return array<string, string|null> */
    public static function snapshot(Tender $tender): array
    {
        $stage = $tender->metadata['rostender']['stage'] ?? $tender->metadata['stage'] ?? null;

        return [
            'deadline_at' => $tender->deadline_at?->utc()->toAtomString(),
            'budget_amount' => $tender->budget_amount,
            'currency' => $tender->currency,
            'stage' => is_string($stage) ? $stage : null,
        ];
    }
}
