<?php

namespace App\Tenders;

use App\Models\SourceFeed;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

class EisRssSource implements TenderSource
{
    public function __construct(private readonly EisRssUrlValidator $urls) {}

    public function fetch(SourceFeed $feed): SourceFetchResult
    {
        $url = $this->urls->canonicalFeedUrl($feed->canonical_url);

        try {
            $response = Http::accept('application/rss+xml, application/xml, text/xml')
                ->timeout(max(1, (int) config('tender.rss.request_timeout_seconds', 30)))
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException) {
            throw new RssSourceException('connection_failed');
        }

        if ($response->redirect()) {
            $location = $response->header('Location');

            if ($location === '') {
                throw new RssSourceException('redirect_not_allowed');
            }

            // A redirect must be an allow-listed HTTPS URL as well. Relative
            // redirects are deliberately rejected until SRC-00 validates them.
            $url = $this->urls->canonicalFeedUrl($location);

            try {
                $response = Http::accept('application/rss+xml, application/xml, text/xml')
                    ->timeout(max(1, (int) config('tender.rss.request_timeout_seconds', 30)))
                    ->withoutRedirecting()
                    ->get($url);
            } catch (ConnectionException) {
                throw new RssSourceException('connection_failed');
            }
        }

        if (! $response->successful()) {
            throw new RssSourceException('http_'.$response->status());
        }

        $body = $response->body();

        if (strlen($body) > (int) config('tender.rss.max_response_bytes', 5 * 1024 * 1024)) {
            throw new RssSourceException('response_too_large');
        }

        if (str_contains(strtolower((string) $response->header('Content-Type')), 'text/html') || preg_match('/\A\s*<(?:!doctype\s+html|html)\b/i', $body)) {
            throw new RssSourceException('html_response');
        }

        return $this->parse($body);
    }

    public function parse(string $xml): SourceFetchResult
    {
        if ($xml === '' || strlen($xml) > (int) config('tender.rss.max_response_bytes', 5 * 1024 * 1024)) {
            throw new RssSourceException('invalid_xml');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $rss = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $rss instanceof SimpleXMLElement || strtolower($rss->getName()) !== 'rss' || ! isset($rss->channel)) {
            throw new RssSourceException('invalid_xml');
        }

        $items = [];

        foreach ($rss->channel->item as $item) {
            $title = $this->plainText((string) ($item->title ?? ''));
            $link = trim((string) ($item->link ?? ''));

            if ($title === '' || $link === '') {
                continue;
            }

            $canonicalUrl = $this->urls->canonicalTenderUrl($link);
            $summary = $this->plainText((string) ($item->description ?? ''));
            $regNumber = $this->registrationNumber($canonicalUrl.' '.$title.' '.$summary);
            $publishedAt = $this->parseDate((string) ($item->pubDate ?? ''));
            $urlHash = hash('sha256', $canonicalUrl);
            $externalId = $regNumber ?? $urlHash;

            $items[] = new EisRssItem(
                externalId: $externalId,
                regNumber: $regNumber,
                canonicalUrl: $canonicalUrl,
                urlHash: $urlHash,
                title: $title,
                summary: $summary === '' ? null : $summary,
                publishedAt: $publishedAt,
                contentHash: hash('sha256', implode('|', [$title, $summary, $canonicalUrl, (string) $publishedAt])),
            );
        }

        return new SourceFetchResult($items);
    }

    private function registrationNumber(string $value): ?string
    {
        preg_match('/(?<!\d)(\d{19,20})(?!\d)/', $value, $matches);

        return $matches[1] ?? null;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function plainText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
