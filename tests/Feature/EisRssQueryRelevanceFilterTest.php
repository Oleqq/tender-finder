<?php

use App\Tenders\EisRssItem;
use App\Tenders\EisRssMatchMode;
use App\Tenders\EisRssQueryRelevanceFilter;
use App\Tenders\EisRssRelevanceCriteria;

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

    $result = app(EisRssQueryRelevanceFilter::class)->filter(
        $items,
        new EisRssRelevanceCriteria('разработка сайта'),
    );

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->externalId)->toBe('relevant')
        ->and($result->reasonsByExternalId['relevant'])->toMatchArray([
            'mode' => 'all',
            'matched_terms' => ['разработка', 'сайта'],
        ]);
});

it('supports any-word and exact-phrase modes while rejecting minus phrases', function () {
    $item = fn (string $id, string $title): EisRssItem => new EisRssItem(
        externalId: $id,
        regNumber: null,
        canonicalUrl: "https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={$id}",
        urlHash: hash('sha256', $id),
        title: $title,
        summary: null,
        publishedAt: null,
        contentHash: hash('sha256', "{$id}-content"),
    );
    $items = [
        $item('exact', 'Техническая поддержка сайта'),
        $item('any', 'Поддержка серверов'),
        $item('excluded', 'Поддержка сайта: строительство нового здания'),
    ];
    $filter = app(EisRssQueryRelevanceFilter::class);

    $any = $filter->filter(
        $items,
        new EisRssRelevanceCriteria(
            'поддержка сайта',
            EisRssMatchMode::Any,
            ['строительство нового здания'],
        ),
    );
    $exact = $filter->filter(
        $items,
        new EisRssRelevanceCriteria(
            'поддержка сайта',
            EisRssMatchMode::Exact,
            ['строительство нового здания'],
        ),
    );

    expect(array_map(fn ($match): string => $match->externalId, $any->items))
        ->toBe(['exact', 'any'])
        ->and($any->reasonsByExternalId['any'])->toBe([
            'mode' => 'any',
            'matched_terms' => ['поддержка'],
            'minus_keywords_checked' => ['строительство нового здания'],
        ])
        ->and(array_map(fn ($match): string => $match->externalId, $exact->items))
        ->toBe(['exact'])
        ->and($exact->reasonsByExternalId['exact']['mode'])->toBe('exact');
});
