<?php

namespace App\Tenders;

class EisRssUrlValidator
{
    public function canonicalFeedUrl(string $url): string
    {
        return $this->canonicalize($url, '/epz/order/extendedsearch/');
    }

    public function canonicalTenderUrl(string $url): string
    {
        return $this->canonicalize($url, '/epz/order/');
    }

    private function canonicalize(string $url, string $pathPrefix): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! isset($parts['host'], $parts['path'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new RssSourceException('url_not_allowed');
        }

        $host = strtolower($parts['host']);
        $path = '/'.ltrim($parts['path'], '/');

        if (! ($host === 'zakupki.gov.ru' || str_ends_with($host, '.zakupki.gov.ru')) || ! str_starts_with($path, $pathPrefix)) {
            throw new RssSourceException('url_not_allowed');
        }

        $query = $this->canonicalQuery($parts['query'] ?? '');

        return 'https://'.$host.$path.($query === '' ? '' : '?'.$query);
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $decodedKey = rawurldecode($key);

            if (str_starts_with(strtolower($decodedKey), 'utm_')) {
                continue;
            }

            $pairs[] = [rawurlencode($decodedKey), rawurlencode(rawurldecode($value))];
        }

        usort($pairs, fn (array $left, array $right): int => $left <=> $right);

        return implode('&', array_map(fn (array $pair): string => $pair[0].'='.$pair[1], $pairs));
    }
}
