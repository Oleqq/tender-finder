<?php

namespace App\Services;

use App\Models\Tender;

final class TenderSummary
{
    /** Only known source facts are returned; no estimated price or inferred terms.
     * @return array<string, string|null>
     */
    public static function forTender(Tender $tender): array
    {
        $metadata = $tender->metadata ?? [];

        return [
            'budget_amount' => $tender->budget_amount,
            'currency' => $tender->currency,
            'published_at' => $tender->published_at?->toAtomString(),
            'deadline_at' => $tender->deadline_at?->toAtomString(),
            'observed_at' => ($tender->details_fetched_at ?? $tender->created_at)->toAtomString(),
            'source_label' => TenderSources::label($tender->source),
            'application_security' => self::text($metadata['application_security'] ?? null),
            'contract_security' => self::text($metadata['contract_security'] ?? null),
            'delivery_place' => self::text($metadata['delivery_place'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
