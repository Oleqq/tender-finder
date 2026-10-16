<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\BidzaarException;
use App\Tenders\SourceFetchResult;
use App\Tenders\TenderSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class BidzaarSource implements TenderSource
{
    public function __construct(private readonly BidzaarResponseParser $parser) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        $configured = app(BidzaarFeedService::class)->configuredFeed();
        if ($configured === null || $feed->source !== 'bidzaar' || $feed->canonical_url !== $configured->canonical_url) {
            throw new BidzaarException('source_disabled');
        }

        $since = $feed->last_success_at?->copy()->subHours(2) ?? now()->subDays(13);
        if ($since->lt(now()->subDays(13))) {
            $since = now()->subDays(13);
        }

        $cursor = $since->utc()->format('Y-m-d\TH:i:s.u\Z');
        $pageSize = 500;
        $items = [];
        $returned = 0;
        for ($page = 0; $page < 100; $page++) {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('tender.bidzaar.user_agent'))
                    ->timeout(max(1, (int) config('tender.bidzaar.request_timeout_seconds', 15)))
                    ->withoutRedirecting()
                    ->get(
                        'https://bidzaar.com/api/aggregator_integrator/'.config('tender.bidzaar.integrator_id').'/procedures/purchases',
                        ['PageSize' => $pageSize, 'FromDate' => $cursor, 'PageNumber' => 0],
                    );
            } catch (ConnectionException) {
                throw new BidzaarException('connection_failed');
            }

            if (! $response->successful()) {
                throw new BidzaarException('http_'.$response->status());
            }
            if (strlen($response->body()) > 8 * 1024 * 1024) {
                throw new BidzaarException('invalid_response');
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new BidzaarException('invalid_response');
            }

            $result = $this->parser->parse($payload);
            $count = count($payload['items']);
            $returned += $count;
            foreach ($result->items as $item) {
                $items[$item->externalId] = $item;
            }
            if ($count < $pageSize) {
                return new SourceFetchResult(array_values($items), $returned);
            }

            // Bidzaar includes the last record again when FromDate equals its
            // lastModified; PageNumber > 0 returned an empty response in live tests.
            $last = end($payload['items']);
            $modified = $last['lastModified'] ?? null;
            try {
                $advanced = is_string($modified) && CarbonImmutable::parse($modified)->greaterThan(CarbonImmutable::parse($cursor));
            } catch (\Throwable) {
                $advanced = false;
            }
            if (! $advanced) {
                throw new BidzaarException('invalid_cursor');
            }
            $cursor = $modified;
        }

        throw new BidzaarException('page_limit_reached');
    }
}
