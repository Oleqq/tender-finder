<?php

namespace App\Services;

use App\Tenders\B2bCenterException;
use App\Tenders\B2bCenterTenderItem;
use App\Tenders\SourceFetchResult;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

final class B2bCenterHtmlParser
{
    public function parse(string $html, string $pageUrl): SourceFetchResult
    {
        if (trim($html) === '') {
            throw new B2bCenterException('invalid_catalog');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new B2bCenterException('invalid_catalog');
        }

        $xpath = new DOMXPath($document);
        $links = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' search-results ')]//tbody/tr/td[1]/a[contains(@href, '/tender-') or contains(@href, '/tenders-')]");

        if ($links === false || $links->length === 0) {
            throw new B2bCenterException('catalog_layout_changed');
        }

        $items = [];

        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            $externalId = $this->externalId($href);
            $row = $this->ancestor($link, 'tr');

            if ($externalId === null || $row === null || isset($items[$externalId])) {
                continue;
            }

            $cells = $this->cells($row);

            if (count($cells) < 4) {
                continue;
            }

            $descriptionNodes = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' search-results-title-desc ')]", $link);
            $descriptionNode = $descriptionNodes === false ? null : $descriptionNodes->item(0);
            $title = $descriptionNode instanceof DOMNode ? $this->text($descriptionNode) : null;
            $linkText = $this->text($link);
            $procedureType = $this->procedureType($linkText, $externalId);

            if ($title === null || $title === '') {
                $title = trim((string) preg_replace('/\\s*№\\s*'.preg_quote($externalId, '/').'.*$/us', '', $linkText));
            }

            if ($title === '') {
                continue;
            }

            $categoryNodes = $xpath->query('./td[1]/small[1]', $row);
            $categoryNode = $categoryNodes === false ? null : $categoryNodes->item(0);
            $category = $categoryNode instanceof DOMNode ? $this->text($categoryNode) : null;
            $organizer = $cells[1] !== '' ? $cells[1] : null;
            $url = $this->canonicalUrl($href, $pageUrl);
            $publishedAt = $this->date($cells[2]);
            $deadlineAt = $this->date($cells[3]);
            $rowText = $this->text($row);

            $items[$externalId] = new B2bCenterTenderItem(
                externalId: $externalId,
                regNumber: $externalId,
                canonicalUrl: $url,
                urlHash: hash('sha256', $url),
                title: $title,
                summary: $category === null ? $procedureType : $procedureType."\nКатегория: ".$category,
                publishedAt: $publishedAt,
                contentHash: hash('sha256', $rowText.'|'.$url),
                deadlineAt: $deadlineAt,
                metadata: [
                    'customer' => $organizer,
                    'category' => $category,
                    'stage' => $procedureType,
                    'b2b_center' => [
                        'organizer' => $organizer,
                        'category' => $category,
                        'procedure_type' => $procedureType,
                        'catalog_url' => $pageUrl,
                    ],
                ],
            );
        }

        if ($items === []) {
            throw new B2bCenterException('catalog_layout_changed');
        }

        return new SourceFetchResult(array_values($items), count($items));
    }

    private function externalId(string $href): ?string
    {
        $path = (string) parse_url($href, PHP_URL_PATH);

        return preg_match('~/(?:tender|tenders)-(\\d+)/?$~i', $path, $match) === 1 ? $match[1] : null;
    }

    private function canonicalUrl(string $href, string $pageUrl): string
    {
        $path = (string) parse_url($href, PHP_URL_PATH);
        $parts = parse_url($pageUrl);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'www.b2b-center.ru').'/'.ltrim($path, '/');
    }

    private function procedureType(string $text, string $externalId): ?string
    {
        $pattern = '/^(.+?)\\s*№\\s*'.preg_quote($externalId, '/').'/us';

        return preg_match($pattern, $text, $match) === 1 ? trim($match[1]) : null;
    }

    private function ancestor(DOMNode $node, string $name): ?DOMElement
    {
        $current = $node->parentNode;

        while ($current !== null) {
            if ($current instanceof DOMElement && strtolower($current->tagName) === $name) {
                return $current;
            }

            $current = $current->parentNode;
        }

        return null;
    }

    /** @return list<string> */
    private function cells(DOMElement $row): array
    {
        $cells = [];

        foreach ($row->childNodes as $node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'td') {
                $cells[] = $this->text($node);
            }
        }

        return $cells;
    }

    private function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/\\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('d.m.Y H:i', trim($value), 'Europe/Moscow');
        } catch (Throwable) {
            return null;
        }
    }
}
