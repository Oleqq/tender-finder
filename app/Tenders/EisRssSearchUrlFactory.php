<?php

namespace App\Tenders;

final class EisRssSearchUrlFactory
{
    public function forPhrase(string $phrase, ?EisRssSearchCriteria $criteria = null): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $phrase) ?? '');

        if (mb_strlen($normalized) < 2 || mb_strlen($normalized) > 120) {
            throw new RssSourceException('invalid_query');
        }

        return 'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html?'.http_build_query([
            'searchString' => $normalized,
            'morphology' => 'on',
            'pageNumber' => 1,
            ...($criteria?->queryParameters() ?? []),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
