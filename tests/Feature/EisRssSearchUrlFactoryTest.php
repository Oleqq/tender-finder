<?php

use App\Tenders\EisRssSearchCriteria;
use App\Tenders\EisRssSearchUrlFactory;
use App\Tenders\RssSourceException;

it('builds a canonical extended-search RSS URL from a phrase', function () {
    $url = app(EisRssSearchUrlFactory::class)->forPhrase('  разработка   сайта ');

    expect($url)->toBe(
        'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html?searchString=%D1%80%D0%B0%D0%B7%D1%80%D0%B0%D0%B1%D0%BE%D1%82%D0%BA%D0%B0%20%D1%81%D0%B0%D0%B9%D1%82%D0%B0&morphology=on&pageNumber=1',
    );
});

it('rejects an empty or too-short automatic search phrase', function () {
    expect(fn () => app(EisRssSearchUrlFactory::class)->forPhrase(' '))
        ->toThrow(RssSourceException::class);
});

it('adds verified EIS law, price and publication date filters to an automatic RSS URL', function () {
    $url = app(EisRssSearchUrlFactory::class)->forPhrase(
        'разработка сайта',
        new EisRssSearchCriteria(
            law44: true,
            budgetFrom: '100000',
            budgetTo: '750000.50',
            publishedFrom: '2026-08-01',
            publishedTo: '2026-08-27',
        ),
    );
    parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

    expect($parameters)->toMatchArray([
        'searchString' => 'разработка сайта',
        'morphology' => 'on',
        'pageNumber' => '1',
        'fz44' => 'on',
        'priceFromGeneral' => '100000',
        'priceToGeneral' => '750000.5',
        'currencyIdGeneral' => '1',
        'publishDateFrom' => '01.08.2026',
        'publishDateTo' => '27.08.2026',
    ])->not->toHaveKey('fz223');
});
