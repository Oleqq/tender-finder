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
    public function __construct(
        private readonly B2bCenterHtmlParser $parser,
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
            || $feed->canonical_url !== $configuredUrl) {
            throw new B2bCenterException('feed_not_configured');
        }

        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent((string) config('tender.b2b_center.user_agent'))
                ->timeout(max(1, (int) config('tender.b2b_center.request_timeout_seconds', 15)))
                ->get($configuredUrl);
        } catch (ConnectionException) {
            throw new B2bCenterException('connection_failed');
        }

        if (! $response->successful()) {
            throw new B2bCenterException('http_'.$response->status());
        }

        return $this->parser->parse($response->body(), $configuredUrl);
    }
}
