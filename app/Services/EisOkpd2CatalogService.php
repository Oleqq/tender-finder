<?php

namespace App\Services;

use App\Tenders\RssSourceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class EisOkpd2CatalogService
{
    private const SEARCH_URL = 'https://zakupki.gov.ru/epz/api/nsi/okpd2/search.html';

    /** @return list<array{id: string, code: string, name: string}> */
    public function search(string $term): array
    {
        $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');

        try {
            $response = Http::acceptJson()
                ->withUserAgent('Mozilla/5.0 (compatible; TenderFinder/1.0; +https://zakupki.gov.ru)')
                ->timeout(max(1, (int) config('tender.rss.request_timeout_seconds', 30)))
                ->withoutRedirecting()
                ->get(self::SEARCH_URL, ['value' => $term]);
        } catch (ConnectionException) {
            throw new RssSourceException('okpd2_catalog_unavailable');
        }

        if (! $response->successful()) {
            throw new RssSourceException('okpd2_catalog_unavailable');
        }

        $payload = $response->json();
        $children = is_array($payload) && is_array($payload['children'] ?? null)
            ? $payload['children']
            : [];
        $items = [];
        $this->collect($children, $items);
        $needle = mb_strtolower($term);

        return collect($items)
            ->filter(fn (array $item): bool => str_contains(mb_strtolower($item['code'].' '.$item['name']), $needle))
            ->unique('code')
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $nodes
     * @param  list<array{id: string, code: string, name: string}>  $items
     */
    private function collect(array $nodes, array &$items): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = $node['key'] ?? null;
            $code = $node['code'] ?? null;
            $name = $node['name'] ?? null;

            if ((is_int($id) || (is_string($id) && ctype_digit($id)))
                && is_string($code) && $code !== ''
                && is_string($name) && trim($name) !== '') {
                $items[] = [
                    'id' => (string) $id,
                    'code' => trim($code),
                    'name' => trim($name),
                ];
            }

            if (is_array($node['children'] ?? null)) {
                $this->collect($node['children'], $items);
            }
        }
    }
}
