<?php

namespace App\Services;

use App\Tenders\SourceFetchResult;
use App\Tenders\WorkspaceRuException;
use App\Tenders\WorkspaceRuTenderItem;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

final class WorkspaceRuRssParser
{
    public function parse(string $xml): SourceFetchResult
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new WorkspaceRuException('invalid_feed');
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('/rss/channel/item');

        if ($nodes === false) {
            throw new WorkspaceRuException('invalid_feed');
        }

        $items = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $title = $this->child($node, 'title');
            $url = $this->child($node, 'link');
            $rawDescription = $this->child($node, 'description');
            $externalId = $url === null ? null : $this->externalId($url);

            if ($title === null || $url === null || $externalId === null || isset($items[$externalId])) {
                continue;
            }

            $service = $this->label($rawDescription, 'Требуем(?:ая услуга|ые услуги)');
            $organizerType = $this->label($rawDescription, 'Организатор');
            $budgetText = $this->label($rawDescription, 'Бюджет');
            $deadlineText = $this->label($rawDescription, 'Крайний срок приема заявок');
            $description = $this->projectDescription($rawDescription);
            [$budgetMin, $budgetMax] = $this->budgetRange($budgetText);
            $summary = implode("\n\n", array_values(array_filter([
                $service === null ? null : 'Требуемая услуга: '.$service,
                $description,
            ])));

            $items[$externalId] = new WorkspaceRuTenderItem(
                externalId: $externalId,
                regNumber: null,
                canonicalUrl: $url,
                urlHash: hash('sha256', $url),
                title: $title,
                summary: $summary === '' ? null : $summary,
                publishedAt: $this->date($this->child($node, 'pubDate')),
                contentHash: hash('sha256', $title.'|'.$url.'|'.$rawDescription),
                budgetAmount: $budgetMax ?? $budgetMin,
                currency: 'RUB',
                deadlineAt: $this->deadline($deadlineText),
                metadata: [
                    'workspace_ru' => [
                        'service' => $service,
                        'organizer_type' => $organizerType,
                        'budget_text' => $budgetText,
                        'budget_min' => $budgetMin,
                        'budget_max' => $budgetMax,
                    ],
                ],
            );
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

    private function externalId(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (($host !== 'workspace.ru' && ! str_ends_with($host, '.workspace.ru'))
            || preg_match('~-(\d+)/?$~', $path, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function label(?string $description, string $label): ?string
    {
        if ($description === null
            || preg_match('~<b>\s*'.$label.'\s*</b>\s*:\s*(.*?)(?:<br\s*/?>|$)~isu', $description, $match) !== 1) {
            return null;
        }

        return $this->plainText($match[1]);
    }

    private function projectDescription(?string $description): ?string
    {
        if ($description === null
            || preg_match('~<b>\s*Описание проекта\s*</b>\s*:\s*(.*?)(?:<br\s*/?>\s*<br\s*/?>\s*<b>\s*Требования к исполнителям|$)~isu', $description, $match) !== 1) {
            return null;
        }

        return $this->plainText($match[1]);
    }

    private function plainText(string $html): ?string
    {
        $withLines = preg_replace('~<\s*(?:br|/p|/li|/div)\b[^>]*>~iu', "\n", $html);
        $text = html_entity_decode(strip_tags($withLines ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[\t ]+/u', ' ', $text));
        $text = trim((string) preg_replace('/\n\s*\n+/u', "\n", $text));

        return $text === '' ? null : $text;
    }

    /** @return array{0: string|null, 1: string|null} */
    private function budgetRange(?string $value): array
    {
        if ($value === null || preg_match_all('/\d[\d\s\x{00A0}]*/u', $value, $matches) < 1) {
            return [null, null];
        }

        $amounts = array_values(array_filter(array_map(function (string $amount): ?string {
            $normalized = preg_replace('/\D/u', '', $amount);

            return is_string($normalized) && $normalized !== ''
                ? number_format((float) $normalized, 2, '.', '')
                : null;
        }, $matches[0])));

        if ($amounts === []) {
            return [null, null];
        }

        if (str_contains(mb_strtolower($value), 'до')) {
            return [null, $amounts[0]];
        }

        if (str_contains(mb_strtolower($value), 'от') && count($amounts) === 1) {
            return [$amounts[0], null];
        }

        return [$amounts[0], $amounts[count($amounts) - 1]];
    }

    private function deadline(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('d.m.Y', $value, 'Europe/Moscow')->endOfDay();
        } catch (Throwable) {
            return null;
        }
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
