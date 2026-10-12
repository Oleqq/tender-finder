<?php

namespace App\Services;

use App\Tenders\EtpGpbException;
use App\Tenders\EtpGpbTenderItem;
use App\Tenders\SourceFetchResult;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

final class EtpGpbRssParser
{
    public function parse(string $xml): SourceFetchResult
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement?->tagName !== 'rss') {
            throw new EtpGpbException('invalid_feed');
        }

        $xpath = new DOMXPath($document);
        $channels = $xpath->query('/rss/channel');
        if ($channels === false || $channels->length !== 1) {
            throw new EtpGpbException('invalid_feed');
        }

        $nodes = $xpath->query('/rss/channel/item');
        if ($nodes === false) {
            throw new EtpGpbException('invalid_feed');
        }

        $items = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $title = $this->child($node, 'title');
            $url = $this->child($node, 'link');
            if ($title === null || $url === null || ! $this->isOfficialProcedureUrl($url)) {
                continue;
            }

            $externalId = hash('sha256', $url);
            $description = $this->child($node, 'description');
            $summary = $description === null ? null : trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $publishedAt = $this->date($this->child($node, 'pubDate'));

            $items[$externalId] = new EtpGpbTenderItem(
                externalId: $externalId,
                regNumber: null,
                canonicalUrl: $url,
                urlHash: $externalId,
                title: $title,
                summary: $summary === '' ? null : $summary,
                publishedAt: $publishedAt,
                contentHash: hash('sha256', $title.'|'.$url.'|'.$description.'|'.$publishedAt?->toIso8601String()),
                metadata: ['etp_gpb' => ['rss' => true]],
            );
        }

        if ($nodes->length > 0 && $items === []) {
            throw new EtpGpbException('invalid_feed');
        }

        return new SourceFetchResult(array_values($items), count($items));
    }

    private function child(DOMElement $element, string $name): ?string
    {
        foreach ($element->childNodes as $node) {
            if ($node->nodeName === $name) {
                $value = trim($node->textContent);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    private function isOfficialProcedureUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && ($parts['scheme'] ?? null) === 'https'
            && in_array($host, ['etpgpb.ru', 'new.etpgpb.ru'], true)
            && str_starts_with((string) ($parts['path'] ?? ''), '/procedures/');
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
