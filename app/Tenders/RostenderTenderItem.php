<?php

namespace App\Tenders;

use Carbon\CarbonImmutable;
use Throwable;

final readonly class RostenderTenderItem extends TenderSourceItem
{
    /** @param array<string, mixed> $detail */
    public static function fromDetail(array $detail): self
    {
        $id = self::positiveInt($detail['id'] ?? null);

        if ($id === null) {
            throw new RostenderApiException('invalid_payload');
        }

        $url = self::string($detail['url'] ?? null) ?? 'https://rostender.info/tender/'.$id;
        $description = self::string($detail['descr'] ?? null);
        $stage = self::string($detail['stage'] ?? null);
        $title = $description ?? ('Тендер РосТендера #'.$id);
        $regions = self::regions($detail['regions'] ?? null);
        $updatedAt = self::date($detail['updated_at'] ?? null);
        $fetchedAt = CarbonImmutable::now();
        $eis = self::string($detail['eis'] ?? null);
        $price = is_array($detail['price'] ?? null) ? $detail['price'] : [];
        $amount = is_numeric($price['value'] ?? null) ? number_format((float) $price['value'], 2, '.', '') : null;

        return new self(
            externalId: (string) $id,
            regNumber: $eis,
            canonicalUrl: $url,
            urlHash: hash('sha256', $url),
            title: $title,
            summary: $stage,
            publishedAt: self::date($detail['dts'] ?? null),
            contentHash: hash('sha256', json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            region: $regions === [] ? self::string($detail['place'] ?? null) : implode(', ', $regions),
            budgetAmount: $amount,
            currency: strtoupper(self::string($price['currency'] ?? null) ?? 'RUB'),
            deadlineAt: self::date($detail['dte-formatted'] ?? $detail['dte'] ?? null),
            metadata: [
                'rostender' => [
                    'type' => self::string($detail['type'] ?? null),
                    'stage' => $stage,
                    'placement' => is_array($detail['placement'] ?? null) ? $detail['placement'] : null,
                    'customer' => is_array($detail['customer'] ?? null) ? $detail['customer'] : null,
                    'regions' => $regions,
                    'cities' => self::stringList($detail['cities'] ?? null),
                    'branches' => self::stringList($detail['branches'] ?? null),
                    'eis' => $eis,
                    'etp' => self::stringList($detail['etp'] ?? null),
                    'links' => is_array($detail['links'] ?? null) ? $detail['links'] : [],
                    'files' => is_array($detail['files'] ?? null) ? $detail['files'] : [],
                ],
            ],
            externalUpdatedAt: $updatedAt,
            detailsFetchedAt: $fetchedAt,
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : (is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null);
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        $value = self::string($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) === '' ? [] : [trim($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $item): ?string => self::string($item), $value)));
    }

    /** @return list<string> */
    private static function regions(mixed $value): array
    {
        return self::stringList($value);
    }
}
