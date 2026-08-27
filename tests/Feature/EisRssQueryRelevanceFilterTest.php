<?php

use App\Tenders\EisRssItem;
use App\Tenders\EisRssQueryRelevanceFilter;

it('does not count the EIS search preamble itself as a relevant tender subject', function () {
    $items = [
        new EisRssItem(
            externalId: 'relevant',
            regNumber: null,
            canonicalUrl: 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=1',
            urlHash: hash('sha256', 'relevant'),
            title: 'Электронный аукцион',
            summary: 'Параметры поиска: разработка сайта. Найденный результат: Оказание услуг по разработке и созданию сайта.',
            publishedAt: null,
            contentHash: hash('sha256', 'relevant-content'),
        ),
        new EisRssItem(
            externalId: 'echo-only',
            regNumber: null,
            canonicalUrl: 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=2',
            urlHash: hash('sha256', 'echo-only'),
            title: 'Электронный аукцион',
            summary: 'Параметры поиска: разработка сайта. Найденный результат: Поставка бумажной продукции.',
            publishedAt: null,
            contentHash: hash('sha256', 'echo-only-content'),
        ),
    ];

    $matches = app(EisRssQueryRelevanceFilter::class)->filter($items, 'разработка сайта');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->externalId)->toBe('relevant');
});
