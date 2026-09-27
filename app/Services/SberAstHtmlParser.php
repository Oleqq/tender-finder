<?php

namespace App\Services;

use App\Tenders\SberAstTenderItem;
use App\Tenders\SourceFetchResult;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

final class SberAstHtmlParser
{
    public function parse(string $html, string $pageUrl): SourceFetchResult
    {
        if (trim($html) === '') {
            return new SourceFetchResult([]);
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return new SourceFetchResult([]);
        }

        $xpath = new DOMXPath($document);
        $links = $xpath->query("//a[contains(translate(@href, 'PURCHASEVIEW', 'purchaseview'), 'purchaseview/')]");
        $items = [];

        if ($links === false) {
            return new SourceFetchResult([]);
        }

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
            $headers = $this->headers($xpath, $row);
            $fields = $this->fields($headers, $cells);
            $title = $this->title($link, $fields, $cells);

            if ($title === null) {
                continue;
            }

            $url = $this->absoluteUrl($href, $pageUrl);
            $rowText = $this->text($row);
            $priceText = $this->field($fields, ['начальн', 'максимальн', 'цена', 'сумма']);
            $customer = $this->field($fields, ['заказчик', 'организатор']);
            $status = $this->field($fields, ['статус', 'состояние']);
            $deadline = $this->date($this->field($fields, ['окончани', 'прием', 'приём', 'подач']));
            $published = $this->date($this->field($fields, ['публикац', 'размещен', 'размещён', 'начало прием', 'начало приём']));
            $regNumber = $this->field($fields, ['номер извещ', 'реестровый номер', 'номер закуп']);

            if ($regNumber === null && preg_match('/\bSBR[\w-]+/iu', $rowText, $match) === 1) {
                $regNumber = $match[0];
            }

            $items[$externalId] = new SberAstTenderItem(
                externalId: $externalId,
                regNumber: $regNumber,
                canonicalUrl: $url,
                urlHash: hash('sha256', $url),
                title: $title,
                summary: $status,
                publishedAt: $published,
                contentHash: hash('sha256', $rowText.'|'.$url),
                budgetAmount: $this->amount($priceText),
                currency: $this->currency($priceText),
                deadlineAt: $deadline,
                metadata: [
                    'customer' => $customer,
                    'stage' => $status,
                    'sber_ast' => [
                        'customer' => $customer,
                        'status' => $status,
                        'registry_url' => $pageUrl,
                    ],
                ],
            );
        }

        return new SourceFetchResult(array_values($items), count($items));
    }

    private function externalId(string $href): ?string
    {
        if (preg_match('~purchaseview/(?:[^/?#]+/)*(\d+)(?:[/?#]|$)~i', $href, $match) !== 1) {
            return null;
        }

        return $match[1];
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
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['td', 'th'], true)) {
                $cells[] = $this->text($node);
            }
        }

        return $cells;
    }

    /** @return list<string> */
    private function headers(DOMXPath $xpath, DOMElement $row): array
    {
        $table = $this->ancestor($row, 'table');

        if ($table === null) {
            return [];
        }

        $nodes = $xpath->query('.//thead//th | .//tr[1]/th', $table);
        $headers = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $headers[] = $this->text($node);
            }
        }

        return $headers;
    }

    /** @param list<string> $headers
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function fields(array $headers, array $cells): array
    {
        $fields = [];

        foreach ($headers as $index => $header) {
            if (isset($cells[$index])) {
                $fields[$this->lower($header)] = $cells[$index];
            }
        }

        return $fields;
    }

    /** @param array<string, string> $fields
     * @param  list<string>  $cells
     */
    private function title(DOMElement $link, array $fields, array $cells): ?string
    {
        $title = $this->field($fields, ['наименование', 'название', 'предмет закуп', 'предмет процедур']);
        $linkText = $this->text($link);

        if ($title === null && $linkText !== '' && ! preg_match('/^(просмотр|подробнее|действия?)$/iu', $linkText)) {
            $title = $linkText;
        }

        if ($title === null) {
            $title = collect($cells)
                ->filter(fn (string $cell): bool => mb_strlen($cell) >= 12 && ! preg_match('/^\d+[\d\s.,]*$/u', $cell))
                ->sortByDesc(fn (string $cell): int => mb_strlen($cell))
                ->first();
        }

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /** @param array<string, string> $fields
     * @param  list<string>  $needles
     */
    private function field(array $fields, array $needles): ?string
    {
        foreach ($fields as $header => $value) {
            foreach ($needles as $needle) {
                if (str_contains($header, $this->lower($needle)) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    private function absoluteUrl(string $href, string $pageUrl): string
    {
        if (preg_match('~^https?://~i', $href) === 1) {
            return $href;
        }

        $parts = parse_url($pageUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'utp.sberbank-ast.ru');

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $path = $parts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin.($directory === '' ? '' : $directory).'/'.$href;
    }

    private function amount(?string $value): ?string
    {
        if ($value === null || preg_match('/\d/u', $value) !== 1) {
            return null;
        }

        $number = preg_replace('/[^\d,.]/u', '', str_replace(["\u{00A0}", ' '], '', $value));

        if (! is_string($number) || $number === '') {
            return null;
        }

        $number = trim($number, '.,');

        $comma = strrpos($number, ',');
        $dot = strrpos($number, '.');
        $decimal = $comma !== false && ($dot === false || $comma > $dot) ? ',' : '.';

        if (substr_count($number, $decimal) > 1) {
            $number = str_replace($decimal, '', $number);
        } else {
            $thousands = $decimal === ',' ? '.' : ',';
            $number = str_replace($thousands, '', $number);
            $number = str_replace($decimal, '.', $number);
        }

        return is_numeric($number) ? number_format((float) $number, 2, '.', '') : null;
    }

    private function currency(?string $value): string
    {
        $value = $this->lower($value ?? '');

        return match (true) {
            str_contains($value, 'usd'), str_contains($value, '$') => 'USD',
            str_contains($value, 'eur'), str_contains($value, '€') => 'EUR',
            default => 'RUB',
        };
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Europe/Moscow');
        } catch (Throwable) {
            return null;
        }
    }

    private function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function lower(string $value): string
    {
        return mb_strtolower($value);
    }
}
