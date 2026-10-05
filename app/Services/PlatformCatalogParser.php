<?php

namespace App\Services;

use App\Tenders\PlatformCatalogException;
use App\Tenders\PlatformCatalogTenderItem;
use App\Tenders\SourceFetchResult;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

final class PlatformCatalogParser
{
    public function parse(string $html, string $source): SourceFetchResult
    {
        if (trim($html) === '' || strlen($html) > 4 * 1024 * 1024) {
            throw new PlatformCatalogException('invalid_catalog');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new PlatformCatalogException('invalid_catalog');
        }

        $xpath = new DOMXPath($document);
        $expected = match ($source) {
            'roseltorg' => 'АО «ЕЭТП»',
            'rts_tender' => 'РТС-тендер',
            'sber_ast_catalog' => 'АО «Сбербанк-АСТ»',
            default => throw new PlatformCatalogException('source_disabled'),
        };
        $heading = $xpath->query('//h1')->item(0);
        if (! $heading instanceof DOMElement || ! str_contains($this->text($heading), $expected)) {
            throw new PlatformCatalogException('catalog_layout_changed');
        }

        $cards = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' cards ') and @itemprop='itemListElement']");
        if ($cards === false || $cards->length === 0) {
            throw new PlatformCatalogException('catalog_layout_changed');
        }

        $items = [];
        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $url = $this->attribute($xpath, ".//meta[@itemprop='url']", 'content', $card);
            $title = $this->attribute($xpath, ".//meta[@itemprop='name']", 'content', $card);
            $nativeLink = $this->attribute($xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' card-item__about ')]//a[@href]", 'href', $card);
            $customer = $this->nodeText($xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' card-item__organization-name ')]", $card);
            $price = $this->money($this->attribute($xpath, ".//meta[@itemprop='price']", 'content', $card));

            if ($url === null || ! preg_match('~^https://www\.b2b-center\.ru/search/number/([a-z0-9-]+)/$~i', $url, $match)
                || $title === null || $title === '') {
                continue;
            }

            $externalId = $match[1];
            $linkScheme = strtolower((string) parse_url($nativeLink ?? '', PHP_URL_SCHEME));
            $linkHost = strtolower((string) parse_url($nativeLink ?? '', PHP_URL_HOST));
            $platformHost = match ($source) {
                'roseltorg' => 'roseltorg.ru',
                'rts_tender' => 'rts-tender.ru',
                'sber_ast_catalog' => 'sberbank-ast.ru',
            };
            $allowedHost = $linkHost === 'zakupki.gov.ru'
                || $linkHost === $platformHost || str_ends_with($linkHost, '.'.$platformHost);
            if (! in_array($linkScheme, ['http', 'https'], true) || ! $allowedHost) {
                // A platform filter returning another operator must not be
                // silently imported under the wrong source label.
                continue;
            }

            $regNumber = null;
            $linkText = $this->nodeText($xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' card-item__about ')]//a[@href]", $card);
            if (preg_match('/№\s*([\d-]{5,30})/u', $linkText ?? '', $number)) {
                $regNumber = $number[1];
            }

            $published = $this->date($this->attribute($xpath, ".//meta[@itemprop='startDate']", 'content', $card));
            $deadline = $this->date($this->attribute($xpath, ".//meta[@itemprop='endDate']", 'content', $card));
            $title = TenderTitle::display(trim((string) preg_replace('/\s+/u', ' ', $title)));
            $metadata = [
                'customer' => $customer,
                'platform' => match ($source) {
                    'roseltorg' => 'Росэлторг',
                    'rts_tender' => 'РТС-Тендер',
                    'sber_ast_catalog' => 'Сбер АСТ',
                },
                'platform_url' => $nativeLink,
                'catalog_provider' => 'B2B-Center',
            ];

            $items[$externalId] = new PlatformCatalogTenderItem(
                externalId: $externalId,
                regNumber: $regNumber,
                canonicalUrl: $url,
                urlHash: hash('sha256', $url),
                title: $title,
                summary: null,
                publishedAt: $published,
                contentHash: hash('sha256', implode('|', [$externalId, $title, $customer ?? '', $price ?? '', $deadline?->toAtomString() ?? ''])),
                budgetAmount: $price,
                deadlineAt: $deadline,
                metadata: $metadata,
            );
        }

        if ($items === []) {
            throw new PlatformCatalogException('catalog_layout_changed');
        }

        return new SourceFetchResult(array_values($items), count($items));
    }

    private function attribute(DOMXPath $xpath, string $query, string $attribute, DOMElement $context): ?string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement && trim($node->getAttribute($attribute)) !== ''
            ? trim($node->getAttribute($attribute)) : null;
    }

    private function nodeText(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMNode ? $this->text($node) : null;
    }

    private function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function money(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $amount = str_replace(',', '.', (string) preg_replace('/[^\d,]/u', '', $value));

        return is_numeric($amount) && (float) $amount > 0 ? number_format((float) $amount, 2, '.', '') : null;
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
