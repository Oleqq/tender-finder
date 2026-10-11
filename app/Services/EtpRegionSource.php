<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\EtpRegionException;
use App\Tenders\SourceFetchResult;
use App\Tenders\TenderSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class EtpRegionSource implements TenderSource
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly EtpRegionResponseParser $parser) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        $configured = app(EtpRegionFeedService::class)->configuredFeed();
        if ($configured === null || $feed->source !== 'etp_region' || $feed->canonical_url !== EtpRegionFeedService::URL) {
            throw new EtpRegionException('source_disabled');
        }

        $cutoff = CarbonImmutable::now('Europe/Moscow')->subDays(14)->startOfDay();
        $items = [];
        $returned = 0;
        $previousDate = null;
        for ($page = 0; $page < max(1, min(20, (int) config('tender.etp_region.pages_per_poll', 10))); $page++) {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('tender.etp_region.user_agent'))
                    ->timeout(max(1, (int) config('tender.etp_region.request_timeout_seconds', 15)))
                    ->withoutRedirecting()
                    ->get($feed->canonical_url, [
                        'query' => json_encode(['types' => ['BUYING']], JSON_THROW_ON_ERROR),
                        'filter' => json_encode(['state' => ['ALL']], JSON_THROW_ON_ERROR),
                        'sort' => json_encode(['placementDate' => false], JSON_THROW_ON_ERROR),
                        'limit' => json_encode(['min' => $page * self::PAGE_SIZE, 'max' => self::PAGE_SIZE, 'updateTotalCount' => $page === 0], JSON_THROW_ON_ERROR),
                    ]);
            } catch (ConnectionException) {
                throw new EtpRegionException('connection_failed');
            }
            if (! $response->successful()) {
                throw new EtpRegionException('http_'.$response->status());
            }
            if (strlen($response->body()) > 4 * 1024 * 1024) {
                throw new EtpRegionException('invalid_response');
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                throw new EtpRegionException('invalid_response');
            }

            $result = $this->parser->parse($payload);
            $returned += $result->itemsReturned ?? 0;
            foreach ($result->items as $item) {
                if ($item->publishedAt !== null && $item->publishedAt->greaterThanOrEqualTo($cutoff)) {
                    $items[$item->externalId] = $item;
                }
            }
            $rows = $payload['list'];
            if ($rows === []) {
                return new SourceFetchResult(array_values($items), $returned);
            }
            foreach ($rows as $row) {
                $date = $this->placementDate($row['placementDate'] ?? null);
                if ($date === null || ($previousDate !== null && $date->greaterThan($previousDate))) {
                    throw new EtpRegionException('invalid_sort_order');
                }
                $previousDate = $date;
            }
            if ($previousDate->lessThan($cutoff) || count($rows) < self::PAGE_SIZE) {
                return new SourceFetchResult(array_values($items), $returned);
            }
        }

        throw new EtpRegionException('page_limit_reached');
    }

    private function placementDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!d.m.Y', $value, 'Europe/Moscow');
        } catch (\Throwable) {
            return null;
        }
    }
}
