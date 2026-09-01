<?php

namespace App\Services;

use App\Models\Tender;
use App\Tenders\EisTenderEnrichmentParser;
use App\Tenders\RssSourceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class EisTenderEnrichmentService
{
    private const PRINT_FORM_URL = 'https://zakupki.gov.ru/epz/order/notice/printForm/view.html';

    public function __construct(private readonly EisTenderEnrichmentParser $parser) {}

    public function enrich(Tender $tender): Tender
    {
        if ($tender->source !== 'eis_rss'
            || ! is_string($tender->reg_number)
            || preg_match('/^\d{19,20}$/', $tender->reg_number) !== 1
        ) {
            throw new RssSourceException('enrichment_not_supported');
        }

        $printHtml = $this->get(self::PRINT_FORM_URL, ['regNumber' => $tender->reg_number]);
        $fields = $this->parser->printForm($printHtml);
        $documentsHtml = $this->get($this->documentsUrl($tender), [
            'regNumber' => $tender->reg_number,
        ]);
        $attachments = $this->parser->attachments($documentsHtml);
        /** @var mixed $rawMetadata */
        $rawMetadata = $tender->getAttribute('metadata');
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];
        $metadata = array_replace($metadata, array_filter([
            'delivery_place' => $fields['delivery_place'],
            'contact_name' => $fields['contact_name'],
            'contact_email' => $fields['contact_email'],
            'contact_phone' => $fields['contact_phone'],
            'postal_address' => $fields['postal_address'],
            'category' => $fields['procedure_method'],
            'application_security' => $fields['application_security'],
            'contract_security' => $fields['contract_security'],
        ], fn (mixed $value): bool => $value !== null));
        $metadata['attachments'] = $attachments;
        $metadata['enriched_at'] = now()->toAtomString();

        $tender->forceFill([
            'deadline_at' => $fields['deadline_at'] ?? $tender->deadline_at,
            'metadata' => $metadata,
        ])->save();

        return $tender->fresh();
    }

    /** @param array<string, string> $query */
    private function get(string $url, array $query): string
    {
        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withUserAgent('Mozilla/5.0 (compatible; TenderFinder/1.0; +https://zakupki.gov.ru)')
                ->timeout(max(1, (int) config('tender.eis_enrichment.request_timeout_seconds', 30)))
                ->withoutRedirecting()
                ->get($url, $query);
        } catch (ConnectionException) {
            throw new RssSourceException('enrichment_unavailable');
        }

        if (! $response->successful()) {
            throw new RssSourceException('enrichment_unavailable');
        }

        $body = $response->body();

        if ($body === ''
            || strlen($body) > (int) config('tender.eis_enrichment.max_response_bytes', 2 * 1024 * 1024)
            || preg_match('/\A\s*<(?:!doctype\s+html|html)\b/i', $body) !== 1
        ) {
            throw new RssSourceException('invalid_enrichment_html');
        }

        return $body;
    }

    private function documentsUrl(Tender $tender): string
    {
        $parts = parse_url($tender->canonical_url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

        if (preg_match('~^/epz/order/notice/[a-z0-9]+/view/common-info\.html$~i', $path) !== 1) {
            throw new RssSourceException('enrichment_not_supported');
        }

        return 'https://zakupki.gov.ru'.str_replace('/common-info.html', '/documents.html', $path);
    }
}
