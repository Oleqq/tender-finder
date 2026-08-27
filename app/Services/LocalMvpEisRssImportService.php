<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Tenders\EisRssQueryRelevanceFilter;
use App\Tenders\EisRssSource;
use App\Tenders\EisRssUrlValidator;
use App\Tenders\RssSourceException;
use App\Tenders\SourceFetchResult;

final class LocalMvpEisRssImportService
{
    public function __construct(
        private readonly EisRssUrlValidator $urls,
        private readonly EisRssSource $source,
        private readonly EisRssQueryRelevanceFilter $relevance,
        private readonly TenderSourceImportService $importer,
    ) {}

    public function import(string $url, string $query, int $pages = 1): LocalMvpEisRssImportResult
    {
        $canonicalUrl = $this->urls->canonicalFeedUrl($url);
        $pages = min(
            max(1, $pages),
            max(1, (int) config('tender.rss.manual_search_max_pages', 3)),
        );
        $itemsSeen = 0;
        $itemsMatched = 0;
        $itemsCreated = 0;
        $pagesLoaded = 0;
        $partiallyLoaded = false;
        $externalIds = [];
        $seenExternalIds = [];

        foreach ($this->pageUrls($canonicalUrl, $pages) as $pageUrl) {
            $this->pauseBeforeNextPage($pagesLoaded);
            $feed = $this->manualFeed($pageUrl);

            try {
                $result = $this->source->fetch($feed);
            } catch (RssSourceException $exception) {
                $this->importer->fail($feed, $exception->codeName, 'eis_rss');

                if ($pagesLoaded === 0) {
                    throw $exception;
                }

                $partiallyLoaded = true;

                break;
            }

            $pagesLoaded++;
            $itemsSeen += count($result->items);
            $matchingItems = array_values(array_filter(
                $this->relevance->filter($result->items, $query),
                function ($item) use (&$seenExternalIds): bool {
                    if (isset($seenExternalIds[$item->externalId])) {
                        return false;
                    }

                    $seenExternalIds[$item->externalId] = true;

                    return true;
                },
            ));
            $itemsMatched += count($matchingItems);
            $run = $this->importer->import(
                $feed,
                new SourceFetchResult($matchingItems, count($result->items)),
                'eis_rss',
                false,
            );
            $itemsCreated += $run->items_created;
            $externalIds = [
                ...$externalIds,
                ...array_map(fn ($item): string => $item->externalId, $matchingItems),
            ];

            if ($result->items === []) {
                break;
            }
        }

        return new LocalMvpEisRssImportResult(
            itemsSeen: $itemsSeen,
            itemsMatched: $itemsMatched,
            itemsCreated: $itemsCreated,
            pagesRequested: $pages,
            pagesLoaded: $pagesLoaded,
            partiallyLoaded: $partiallyLoaded,
            externalIds: array_values(array_unique($externalIds)),
        );
    }

    /** @return list<string> */
    private function pageUrls(string $canonicalUrl, int $pages): array
    {
        $parts = parse_url($canonicalUrl);
        $startPage = $this->pageNumber($parts['query'] ?? '');

        return array_map(
            fn (int $page): string => $this->withPageNumber($canonicalUrl, $page),
            range($startPage, $startPage + $pages - 1),
        );
    }

    private function pageNumber(string $query): int
    {
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (strcasecmp(rawurldecode($key), 'pageNumber') !== 0) {
                continue;
            }

            $page = rawurldecode($value);

            return ctype_digit($page) && (int) $page > 0 ? (int) $page : 1;
        }

        return 1;
    }

    private function withPageNumber(string $canonicalUrl, int $page): string
    {
        $parts = parse_url($canonicalUrl);
        $query = [];

        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key] = explode('=', $pair, 2);

            if (strcasecmp(rawurldecode($key), 'pageNumber') === 0) {
                continue;
            }

            $query[] = $pair;
        }

        $query[] = rawurlencode('pageNumber').'='.rawurlencode((string) $page);

        return $this->urls->canonicalFeedUrl(
            'https://'.$parts['host'].$parts['path'].'?'.implode('&', $query),
        );
    }

    private function pauseBeforeNextPage(int $pagesLoaded): void
    {
        if ($pagesLoaded === 0) {
            return;
        }

        $milliseconds = min(
            5_000,
            max(0, (int) config('tender.rss.global_min_interval_milliseconds', 1_500)),
        );

        if ($milliseconds > 0) {
            usleep($milliseconds * 1_000);
        }
    }

    private function manualFeed(string $canonicalUrl): SourceFeed
    {
        $urlHash = hash('sha256', $canonicalUrl);
        $feed = SourceFeed::query()->where('url_hash', $urlHash)->first();

        if ($feed !== null) {
            if ($feed->status !== 'manual_preview') {
                throw new RssSourceException('feed_not_manual');
            }

            return $feed;
        }

        return SourceFeed::query()->create([
            'canonical_url' => $canonicalUrl,
            'url_hash' => $urlHash,
            'status' => 'manual_preview',
            'poll_interval_seconds' => 0,
        ]);
    }
}
