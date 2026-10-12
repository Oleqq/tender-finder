<?php

namespace App\Services;

use App\Tenders\BidzaarException;
use App\Tenders\BidzaarTenderItem;
use App\Tenders\SourceFetchResult;
use Carbon\CarbonImmutable;
use Throwable;

final class BidzaarResponseParser
{
    /** @param array<string, mixed> $payload */
    public function parse(array $payload): SourceFetchResult
    {
        if (! isset($payload['items']) || ! is_array($payload['items']) || ! array_is_list($payload['items'])) {
            throw new BidzaarException('invalid_response');
        }

        $items = [];
        foreach ($payload['items'] as $row) {
            if (! is_array($row)) {
                throw new BidzaarException('invalid_response');
            }

            $number = $this->string($row['number'] ?? null);
            $title = $this->string($row['name'] ?? null);
            $url = $this->string($row['link'] ?? null);
            $parts = $url === null ? [] : parse_url($url);
            if ($number === null || $title === null || $url === null || $parts === false
                || ($parts['scheme'] ?? null) !== 'https'
                || ! in_array(strtolower((string) ($parts['host'] ?? '')), ['bidzaar.com', 'www.bidzaar.com'], true)
                || ! str_starts_with((string) ($parts['path'] ?? ''), '/app/process/')) {
                throw new BidzaarException('invalid_response');
            }

            $published = $this->date($row['publishDate'] ?? null);
            $deadline = $this->date($row['acceptanceEndDate'] ?? null);
            if ($published === null || $deadline === null) {
                throw new BidzaarException('invalid_response');
            }

            $status = $this->string($row['status'] ?? null);
            if (! in_array($status, ['proposal', 'summarizing', 'ended'], true)) {
                throw new BidzaarException('invalid_response');
            }

            $price = $row['price'] ?? null;
            $budget = (is_int($price) || is_float($price)) && $price > 0
                ? number_format((float) $price, 2, '.', '') : null;
            $description = $this->string($row['description'] ?? null);
            $updated = $this->date($row['lastModified'] ?? null);
            $currency = $this->string($row['currency'] ?? null) ?? 'RUB';

            $items[$number] = new BidzaarTenderItem(
                externalId: $number,
                regNumber: $number,
                canonicalUrl: $url,
                urlHash: hash('sha256', $url),
                title: TenderTitle::display($title),
                summary: $description,
                publishedAt: $published,
                contentHash: hash('sha256', implode('|', [$number, $title, $description ?? '', $budget ?? '', $deadline->toAtomString(), $status])),
                budgetAmount: $budget,
                currency: $currency,
                deadlineAt: $deadline,
                metadata: ['customer' => $this->string($row['publisherName'] ?? null), 'bidzaar' => ['status' => $status]],
                externalUpdatedAt: $updated,
            );
        }

        return new SourceFetchResult(array_values($items), count($items));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
