<?php

use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\TenderSourceImportService;
use App\Tenders\EisRssItem;
use App\Tenders\SourceFetchResult;

it('refreshes an existing tender when the source sends cleaner data', function () {
    $feed = SourceFeed::query()->create([
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html?searchString=test',
        'url_hash' => hash('sha256', 'refresh-feed'),
        'status' => 'manual_preview',
        'poll_interval_seconds' => 0,
    ]);
    $importer = app(TenderSourceImportService::class);
    $first = eisItemForRefresh('Технический заголовок', 'Старое описание', null, 'old');
    $clean = eisItemForRefresh('Понятный предмет закупки', 'Электронный аукцион · 44-ФЗ', '728800.00', 'clean');

    $importer->import($feed, new SourceFetchResult([$first]), 'eis_rss', false);
    $run = $importer->import($feed->fresh(), new SourceFetchResult([$clean]), 'eis_rss', false);

    expect($run->items_created)->toBe(0)
        ->and(Tender::query()->where('source', 'eis_rss')->sole()->title)->toBe('Понятный предмет закупки')
        ->and(Tender::query()->where('source', 'eis_rss')->sole()->description)->toBe('Электронный аукцион · 44-ФЗ')
        ->and(Tender::query()->where('source', 'eis_rss')->sole()->budget_amount)->toBe('728800.00');
});

function eisItemForRefresh(string $title, string $summary, ?string $budgetAmount, string $content): EisRssItem
{
    return new EisRssItem(
        externalId: '01234567890123456789',
        regNumber: '01234567890123456789',
        canonicalUrl: 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456789',
        urlHash: hash('sha256', 'same-tender'),
        title: $title,
        summary: $summary,
        publishedAt: null,
        contentHash: hash('sha256', $content),
        budgetAmount: $budgetAmount,
    );
}
