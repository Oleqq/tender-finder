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
        } catch (ConnectionException $exception) {
            throw new RssSourceException($this->connectionErrorCode($exception));
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
            } catch (ConnectionException $exception) {
                throw new RssSourceException($this->connectionErrorCode($exception));
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

            try {
                $canonicalUrl = $this->urls->canonicalTenderUrl($link);
            } catch (RssSourceException) {
                // An occasional malformed item must not discard an otherwise
                // valid EIS feed. Its link is never requested or persisted.
                continue;
            }
            $summary = $this->plainText((string) ($item->description ?? ''));
            $normalized = $this->normalizeTender($title, $summary);
            $regNumber = $this->registrationNumber($canonicalUrl.' '.$title.' '.$summary);
            $publishedAt = $this->parseDate((string) ($item->pubDate ?? ''));
            $urlHash = hash('sha256', $canonicalUrl);
            $externalId = $regNumber ?? $urlHash;

            $items[] = new EisRssItem(
                externalId: $externalId,
                regNumber: $regNumber,
                canonicalUrl: $canonicalUrl,
                urlHash: $urlHash,
                title: $normalized['title'],
                summary: $normalized['summary'],
                publishedAt: $publishedAt,
                contentHash: hash('sha256', implode('|', [
                    $normalized['title'],
                    $normalized['summary'] ?? '',
                    $normalized['budgetAmount'] ?? '',
                    json_encode($normalized['metadata'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $canonicalUrl,
                    (string) $publishedAt,
                ])),
                budgetAmount: $normalized['budgetAmount'],
                metadata: $normalized['metadata'],
            );
        }

        return new SourceFetchResult($items);
    }

    private function registrationNumber(string $value): ?string
    {
        preg_match('/(?<!\d)(\d{19,20})(?!\d)/', $value, $matches);

        return $matches[1] ?? null;
    }

    /**
     * @return array{title: string, summary: string|null, budgetAmount: string|null, metadata: array<string, string>}
     */
    private function normalizeTender(string $procedureTitle, string $rawSummary): array
    {
        $subject = $this->field($rawSummary, 'Наименование объекта закупки', [
            'Начальная цена контракта',
            'Валюта',
            'Размещено',
            'Обновлено',
            'Этап размещения',
            'Идентификационный код закупки',
            'Наименование Заказчика',
            'Размещение выполняется по',
        ]);
        $customer = $this->field($rawSummary, 'Наименование Заказчика', [
            'Начальная цена контракта',
            'Валюта',
            'Размещено',
            'Обновлено',
            'Этап размещения',
            'Идентификационный код закупки',
        ]);
        $procedureType = trim(preg_replace('/\s*№\s*\S+.*$/u', '', $procedureTitle) ?? $procedureTitle);
        $law = $this->procurementLaw($rawSummary);
        $budgetAmount = $this->money($this->field($rawSummary, 'Начальная цена контракта', [
            'Валюта',
            'Размещено',
            'Обновлено',
            'Этап размещения',
            'Идентификационный код закупки',
        ]));
        $summary = $subject === null
            ? $this->excerpt($this->withoutSearchPreamble($rawSummary))
            : $this->compactSummary($procedureType, $law);

        return [
            'title' => $subject ?? $procedureTitle,
            'summary' => $summary,
            'budgetAmount' => $budgetAmount,
            'metadata' => array_filter([
                'customer' => $customer,
                'category' => $procedureType,
                'procurement_law' => $law,
            ]),
        ];
    }

    /** @param list<string> $nextLabels */
    private function field(string $value, string $label, array $nextLabels): ?string
    {
        $next = implode('|', array_map(
            fn (string $nextLabel): string => preg_quote($nextLabel, '/'),
            $nextLabels,
        ));
        $pattern = '/'.preg_quote($label, '/').'\s*:\s*(.*?)(?=(?:'.$next.')\s*:|$)/uis';

        if (preg_match($pattern, $value, $matches) !== 1) {
            return null;
        }

        $result = $this->plainText($matches[1]);

        return $result === '' ? null : $result;
    }

    private function procurementLaw(string $value): ?string
    {
        if (preg_match('/Размещение\s+выполняется\s+по\s*:\s*(44|223)\s*-\s*ФЗ/ui', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function money(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(["\xC2\xA0", ' '], '', $value);
        $normalized = str_replace(',', '.', $normalized);

        if (preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $normalized) !== 1) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function compactSummary(string $procedureType, ?string $law): ?string
    {
        $parts = array_filter([
            $procedureType,
            $law === null ? null : $law.'-ФЗ',
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function withoutSearchPreamble(string $value): string
    {
        return preg_replace('/\A.*?найденный\s+результат\s*:\s*/uis', '', $value) ?? $value;
    }

    private function excerpt(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_strimwidth($value, 0, 360, '…', 'UTF-8');
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

    private function connectionErrorCode(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'certificate')
            || str_contains($message, 'curl error 60')
            || str_contains($message, 'ssl')
            || str_contains($message, 'tls')) {
            return 'tls_failed';
        }

        return 'connection_failed';
    }
}
