<?php

namespace App\Services;

use App\Tenders\EtpRegionException;
use App\Tenders\EtpRegionTenderItem;
use App\Tenders\SourceFetchResult;
use Carbon\CarbonImmutable;
use Throwable;

final class EtpRegionResponseParser
{
    /** @param array<string, mixed> $payload */
    public function parse(array $payload): SourceFetchResult
    {
        if (! isset($payload['list']) || ! is_array($payload['list']) || ! array_is_list($payload['list'])
            || ! is_int($payload['count'] ?? null) || $payload['count'] !== count($payload['list'])) {
            throw new EtpRegionException('invalid_response');
        }

        $items = [];
        foreach ($payload['list'] as $row) {
            if (! is_array($row)) {
                throw new EtpRegionException('invalid_response');
            }
            $type = $this->string($row['type'] ?? null);
            $state = is_array($row['state'] ?? null) ? $this->string($row['state']['code'] ?? null) : null;
            if ($type === null || ! str_starts_with($type, 'BUYING_') || $state === null) {
                throw new EtpRegionException('invalid_response');
            }
            if ($state !== 'PurchaseLot_St_Dmnds_Started') {
                continue;
            }

            $uuid = $this->string($row['uuid'] ?? null);
            $title = $this->string($row['title'] ?? null);
            $url = $this->string($row['lotLink'] ?? null);
            $parts = $url === null ? [] : parse_url($url);
            if ($uuid === null || $title === null || $url === null || $parts === false
                || ($parts['scheme'] ?? null) !== 'https'
                || ($parts['host'] ?? null) !== 'torgi.etp-region.ru'
                || ($parts['path'] ?? null) !== '/app/LotCard/page') {
                throw new EtpRegionException('invalid_response');
            }

            $published = $this->date($row['placementDate'] ?? null);
            $deadline = $this->deadline($row['gdEndDate'] ?? null);
            if ($published === null || $deadline === null) {
                throw new EtpRegionException('invalid_response');
            }

            $priceText = html_entity_decode(strip_tags((string) ($row['price'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $priceText = preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $priceText) ?? '';
            $amount = null;
            if (str_contains($priceText, 'руб') && preg_match('/\d[\d ]*(?:[.,]\d{2})?/u', $priceText, $match) === 1) {
                $amount = str_replace([' ', ','], ['', '.'], $match[0]);
            }
            $customer = is_array($row['organizer'] ?? null) ? $this->string($row['organizer']['title'] ?? null) : null;
            $number = $this->string($row['identifier'] ?? null);
            $summary = $this->string($row['placementType'] ?? null);
            $region = is_array($row['regionCodes'] ?? null) ? $this->string($row['regionCodes'][0] ?? null) : null;
            $items[$uuid] = new EtpRegionTenderItem(
                externalId: $uuid,
                regNumber: $number,
                canonicalUrl: $url,
                urlHash: hash('sha256', $url),
                title: TenderTitle::display($title),
                summary: $summary,
                publishedAt: $published,
                contentHash: hash('sha256', implode('|', [$uuid, $title, $summary ?? '', $amount ?? '', $deadline->toAtomString(), $state])),
                region: $region,
                budgetAmount: $amount,
                currency: 'RUB',
                deadlineAt: $deadline,
                metadata: ['customer' => $customer, 'etp_region' => ['state' => $state]],
            );
        }

        return new SourceFetchResult(array_values($items), count($payload['list']));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!d.m.Y', $value, 'Europe/Moscow');
        } catch (Throwable) {
            return null;
        }
    }

    private function deadline(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/^(\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}).*МСК([+-]\d{1,2})?/u', $text, $match) !== 1) {
            return null;
        }
        $offset = 3 + (int) ($match[2] ?? 0);
        if ($offset < -12 || $offset > 14) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!d.m.Y H:i', $match[1], sprintf('%+03d:00', $offset));
        } catch (Throwable) {
            return null;
        }
    }
}
