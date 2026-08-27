<?php

namespace App\Tenders;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class TenderGuruPreviewSource
{
    private const ENDPOINT = 'https://www.tenderguru.ru/api2.3/export';

    private const MAX_RESPONSE_BYTES = 1024 * 1024;

    /** @var list<string> */
    private const QUERY_STOP_WORDS = [
        'а', 'без', 'в', 'во', 'для', 'до', 'и', 'из', 'или', 'к', 'как', 'на', 'над',
        'не', 'но', 'о', 'об', 'от', 'по', 'под', 'при', 'про', 'с', 'со', 'у', 'что',
    ];

    public function fetch(string $query): SourceFetchResult
    {
        $query = $this->normalizedQuery($query);

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withoutRedirecting()
                ->get(self::ENDPOINT, [
                    'kwords' => $this->sourceQuery($query),
                    'dtype' => 'json',
                    'actual' => 1,
                ]);
        } catch (ConnectionException) {
            throw new TenderGuruPreviewException('connection_failed');
        }

        if ($response->redirect()) {
            throw new TenderGuruPreviewException('redirect_not_allowed');
        }

        if (! $response->successful()) {
            throw new TenderGuruPreviewException('http_'.$response->status());
        }

        return $this->parse($response->body(), $query);
    }

    public function parse(string $json, ?string $query = null): SourceFetchResult
    {
        if ($json === '' || strlen($json) > self::MAX_RESPONSE_BYTES) {
            throw new TenderGuruPreviewException('invalid_json');
        }

        try {
            $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TenderGuruPreviewException('invalid_json');
        }

        if (! is_array($rows)) {
            throw new TenderGuruPreviewException('invalid_json');
        }

        $items = [];
        $itemsReturned = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['ID'] ?? ''));
            $title = $this->plainText((string) ($row['TenderName'] ?? ''));

            if (! preg_match('/\A\d{1,20}\z/', $id) || $title === '') {
                continue;
            }

            $itemsReturned++;

            $summary = $this->summary($row['searchFragmentXML']['fragment'] ?? null);

            if ($query !== null && ! $this->matchesQuery($query, $title)) {
                continue;
            }

            $canonicalUrl = "https://www.tenderguru.ru/tender/{$id}";
            $publishedAt = $this->parseDate((string) ($row['Date'] ?? ''));
            $deadlineAt = $this->parseDate((string) ($row['EndTime'] ?? ''));
            $region = $this->nullableText($row['Region'] ?? null);
            $budgetAmount = $this->money($row['Price'] ?? null);
            $law = $this->procurementLaw($row['Fz'] ?? null);

            $items[] = new TenderGuruPreviewItem(
                externalId: $id,
                regNumber: $this->registrationNumber($row['TenderNumOuter'] ?? null),
                canonicalUrl: $canonicalUrl,
                urlHash: hash('sha256', $canonicalUrl),
                title: $title,
                summary: $summary,
                publishedAt: $publishedAt,
                contentHash: hash('sha256', implode('|', [$id, $title, $summary ?? '', $region ?? '', $budgetAmount ?? '', (string) $deadlineAt])),
                region: $region,
                budgetAmount: $budgetAmount,
                deadlineAt: $deadlineAt,
                metadata: array_filter([
                    'provider' => 'tenderguru_preview',
                    'category' => $this->nullableText($row['Category'] ?? null),
                    'procurement_law' => $law,
                ], fn (?string $value): bool => $value !== null),
            );
        }

        return new SourceFetchResult($items, $itemsReturned);
    }

    private function normalizedQuery(string $query): string
    {
        $query = str_replace('"', '', $this->plainText($query));

        if (mb_strlen($query) < 2 || mb_strlen($query) > 120) {
            throw new TenderGuruPreviewException('invalid_query');
        }

        return $query;
    }

    private function sourceQuery(string $query): string
    {
        return count($this->queryTerms($query)) >= 2
            ? '"'.$query.'"'
            : $query;
    }

    private function matchesQuery(string $query, string $title): bool
    {
        $terms = $this->queryTerms($query);

        if ($terms === []) {
            return true;
        }

        $haystack = $this->normalizeSearchText($title);

        foreach ($terms as $term) {
            if (! str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function queryTerms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $this->normalizeSearchText($query), $matches);

        $terms = array_values(array_filter(
            $matches[0],
            fn (string $word): bool => mb_strlen($word) >= 3
                && ! in_array($word, self::QUERY_STOP_WORDS, true),
        ));

        return array_values(array_unique(array_map(
            $this->searchStem(...),
            $terms,
        )));
    }

    private function normalizeSearchText(string $value): string
    {
        return mb_strtolower(str_replace('ё', 'е', $this->plainText($value)), 'UTF-8');
    }

    private function searchStem(string $word): string
    {
        $length = mb_strlen($word);

        if ($length >= 6) {
            return mb_substr($word, 0, $length - 2);
        }

        if ($length >= 5) {
            return mb_substr($word, 0, $length - 1);
        }

        return $word;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!d-m-Y', $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function registrationNumber(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';

        preg_match('/(?<!\d)(\d{19,20})(?!\d)/', $value, $matches);

        return $matches[1] ?? null;
    }

    private function money(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', preg_replace('/[^\d,.-]/u', '', $value) ?? '');

        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function procurementLaw(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return in_array($value, ['44', '223', 'kom'], true) ? $value : null;
    }

    private function summary(mixed $fragments): ?string
    {
        if (! is_array($fragments)) {
            return null;
        }

        $value = $this->plainText(implode(' ', array_filter($fragments, 'is_string')));

        return $value === '' ? null : $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = is_scalar($value) ? $this->plainText((string) $value) : '';

        return $value === '' ? null : $value;
    }

    private function plainText(string $value): string
    {
        $value = str_replace(['[B]', '[/B]'], '', $value);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
