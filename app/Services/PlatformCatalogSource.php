<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\PlatformCatalogException;
use App\Tenders\SourceFetchResult;
use App\Tenders\TenderSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class PlatformCatalogSource implements TenderSource
{
    public function __construct(private readonly PlatformCatalogParser $parser) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        $source = $feed->source;
        $url = PlatformCatalogFeedService::URLS[$source] ?? null;
        if ($url === null || ! config("tender.platform_catalog.{$source}.enabled") || $feed->canonical_url !== $url) {
            throw new PlatformCatalogException('source_disabled');
        }

        $items = [];
        $maxPages = max(1, min(10, (int) config('tender.platform_catalog.pages_per_poll', 5)));
        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->fetchPage($url, $page);

            if (! $response->successful()) {
                throw new PlatformCatalogException('http_'.$response->status());
            }

            foreach ($this->parser->parse($response->body(), $source)->items as $item) {
                $items[$item->externalId] = $item;
            }
        }

        return new SourceFetchResult(array_values($items), count($items));
    }

    private function fetchPage(string $url, int $page): Response
    {
        try {
            return $this->requestPage($url, $page);
        } catch (ConnectionException) {
            usleep(500_000);
        }

        try {
            return $this->requestPage($url, $page);
        } catch (ConnectionException) {
            throw new PlatformCatalogException('connection_failed');
        }
    }

    private function requestPage(string $url, int $page): Response
    {
        return Http::accept('text/html,application/xhtml+xml')
            ->withUserAgent((string) config('tender.platform_catalog.user_agent'))
            ->timeout(max(1, (int) config('tender.platform_catalog.request_timeout_seconds', 15)))
            ->withoutRedirecting()
            ->get($url, $page === 1 ? [] : ['Page' => $page]);
    }
}
