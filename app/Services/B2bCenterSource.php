<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\B2bCenterException;
use App\Tenders\SourceFetchResult;
use App\Tenders\TenderSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class B2bCenterSource implements TenderSource
{
    private const SEARCH_URL = 'https://www.b2b-center.ru/site/api/v1/market-search/';

    public function __construct(
        private readonly B2bCenterSearchApiParser $parser,
        private readonly B2bCenterFeedService $feeds,
    ) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        $configuredUrl = config('tender.b2b_center.catalog_url');

        if (! config('tender.b2b_center.enabled') || $feed->source !== 'b2b_center') {
            throw new B2bCenterException('source_disabled');
        }

        if (! is_string($configuredUrl)
            || ! $this->feeds->isOfficialCatalogUrl($configuredUrl)
            || ! $this->isConfiguredUrl($feed->canonical_url, $configuredUrl)) {
            throw new B2bCenterException('feed_not_configured');
        }

        parse_str((string) parse_url($feed->canonical_url, PHP_URL_QUERY), $parameters);
        $phrase = $parameters['f_keyword'] ?? null;
        $items = [];
        $previousPageIds = null;
        $maxPages = max(1, min(10, (int) config('tender.b2b_center.pages_per_poll', 5)));
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $response = Http::acceptJson()
                    ->withUserAgent((string) config('tender.b2b_center.user_agent'))
                    ->timeout(max(1, (int) config('tender.b2b_center.request_timeout_seconds', 15)))
                    ->withoutRedirecting()
                    ->get(self::SEARCH_URL, array_filter([
                        'query' => $phrase,
                        'page' => $page,
                        'page_size' => 20,
                        'sort' => 'date_desc',
                        'macro_trade_type' => 'buy',
                        'tab' => 'actual',
                        'company_type' => 2,
                    ], fn ($value) => $value !== null));
            } catch (ConnectionException) {
                throw new B2bCenterException('connection_failed');
            }
            if (! $response->successful()) {
                throw new B2bCenterException('http_'.$response->status());
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new B2bCenterException('invalid_catalog');
            }
            $result = $this->parser->parse($data);
            $pageIds = array_map(fn ($item) => $item->externalId, $result['items']);
            if ($pageIds === $previousPageIds) {
                // The guest search currently ignores page/page_size and can
                // repeat its first page while reporting many pages available.
                break;
            }
            $previousPageIds = $pageIds;
            foreach ($result['items'] as $item) {
                $items[$item->externalId] = $item;
            }
            if ($page >= $result['page_count']) {
                break;
            }
        }

        return new SourceFetchResult(array_values($items), count($items));
    }

    private function isConfiguredUrl(string $url, string $base): bool
    {
        if ($url === $base) {
            return true;
        }
        if (! str_starts_with($url, $base.'?')) {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        $phrase = $parameters['f_keyword'] ?? null;

        return is_string($phrase) && trim($phrase) !== '' && mb_strlen($phrase) <= 255
            && $url === $base.'?'.http_build_query(['f_keyword' => $phrase, 'searching' => 1]);
    }
}
