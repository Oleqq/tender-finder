<?php

namespace App\Tenders;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class EisTenderEnrichmentParser
{
    /**
     * @return array{
     *     deadline_at: CarbonImmutable|null,
     *     delivery_place: string|null,
     *     contact_name: string|null,
     *     contact_email: string|null,
     *     contact_phone: string|null,
     *     postal_address: string|null,
     *     procedure_method: string|null,
     *     application_security: string|null,
     *     contract_security: string|null
     * }
     */
    public function printForm(string $html): array
    {
        $xpath = $this->xpath($html);
        $fields = $this->parameterFields($xpath);

        return [
            'deadline_at' => $this->date($this->first($fields, [
                'Дата и время окончания подачи заявок',
                'Дата и время окончания срока подачи заявок',
            ])),
            'delivery_place' => $this->first($fields, [
                'Место поставки товара, выполнения работы или оказания услуги',
                'Место доставки товара, место выполнения работы или оказания услуги',
            ]),
            'contact_name' => $this->first($fields, [
                'Ответственное должностное лицо',
                'Фамилия, имя, отчество (при наличии)',
                'Контактное лицо',
            ]),
            'contact_email' => $this->first($fields, ['Адрес электронной почты']),
            'contact_phone' => $this->first($fields, ['Номер контактного телефона']),
            'postal_address' => $this->first($fields, ['Почтовый адрес']),
            'procedure_method' => $this->first($fields, [
                'Способ определения поставщика (подрядчика, исполнителя)',
                'Способ определения поставщика',
            ]),
            'application_security' => $this->security(
                $fields,
                ['Размер обеспечения заявки', 'Размер обеспечения заявок'],
                ['Обеспечение заявок не требуется', 'Обеспечение заявки не требуется'],
            ),
            'contract_security' => $this->security(
                $fields,
                ['Размер обеспечения исполнения контракта'],
                ['Обеспечение исполнения контракта не требуется'],
            ),
        ];
    }

    /** @return list<array{label: string, url: string, mime_type: null, size_bytes: null}> */
    public function attachments(string $html): array
    {
        $xpath = $this->xpath($html);
        $result = [];
        $seen = [];
        $limit = max(1, (int) config('tender.eis_enrichment.max_attachments', 30));

        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $url = $this->attachmentUrl($node->getAttribute('href'));

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $label = $this->text($node->textContent);

            if ($label === '') {
                $label = 'Документ ЕИС';
            }

            $seen[$url] = true;
            $result[] = [
                'label' => mb_strimwidth($label, 0, 240, '…', 'UTF-8'),
                'url' => $url,
                'mime_type' => null,
                'size_bytes' => null,
            ];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    private function xpath(string $html): DOMXPath
    {
        if ($html === '') {
            throw new RssSourceException('invalid_enrichment_html');
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;

        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new RssSourceException('invalid_enrichment_html');
        }

        return new DOMXPath($dom);
    }

    /** @return array<string, list<string>> */
    private function parameterFields(DOMXPath $xpath): array
    {
        $fields = [];
        $nodes = $xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " parameter ")]');

        foreach ($nodes ?: [] as $labelNode) {
            $label = $this->text($labelNode->textContent);

            if ($label === '') {
                continue;
            }

            $row = $this->ancestor($labelNode, 'tr');
            $value = $row === null ? null : $this->rowValue($xpath, $row);
            $fields[$label] ??= [];

            if ($value !== null) {
                $fields[$label][] = $value;
            }
        }

        return $fields;
    }

    private function ancestor(DOMNode $node, string $name): ?DOMNode
    {
        $current = $node->parentNode;

        while ($current !== null) {
            if (strtolower($current->nodeName) === $name) {
                return $current;
            }

            $current = $current->parentNode;
        }

        return null;
    }

    private function rowValue(DOMXPath $xpath, DOMNode $row): ?string
    {
        $values = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " parameterValue ")]', $row);

        foreach ($values ?: [] as $valueNode) {
            $value = $this->text($valueNode->textContent);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  list<string>  $labels
     */
    private function first(array $fields, array $labels): ?string
    {
        foreach ($labels as $label) {
            foreach ($fields[$label] ?? [] as $value) {
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  list<string>  $valueLabels
     * @param  list<string>  $notRequiredLabels
     */
    private function security(array $fields, array $valueLabels, array $notRequiredLabels): ?string
    {
        $value = $this->first($fields, $valueLabels);

        if ($value !== null) {
            return $value;
        }

        foreach ($notRequiredLabels as $label) {
            if (array_key_exists($label, $fields)) {
                return 'Не требуется';
            }
        }

        return null;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        foreach (['d.m.Y H:i', 'd.m.Y H:i:s'] as $format) {
            if (CarbonImmutable::hasFormat($value, $format)) {
                return CarbonImmutable::createFromFormat(
                    $format,
                    $value,
                    (string) config('app.timezone'),
                );
            }
        }

        return null;
    }

    private function attachmentUrl(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (str_starts_with($value, '/')) {
            $value = 'https://zakupki.gov.ru'.$value;
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || strtolower((string) ($parts['host'] ?? '')) !== 'zakupki.gov.ru'
            || isset($parts['user'], $parts['pass'], $parts['port'])
        ) {
            return null;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');

        if (str_contains($path, '\\')) {
            return null;
        }

        $decodedPath = rawurldecode($path);

        if (preg_match('~(?:^|/)\.\.?($|/)~', $decodedPath) === 1
            || str_contains($decodedPath, '\\')
        ) {
            return null;
        }

        if (preg_match('~^/(?:44fz|223fz)/filestore/public/~', $path) !== 1) {
            return null;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://zakupki.gov.ru'.$path.$query;
    }

    private function text(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
