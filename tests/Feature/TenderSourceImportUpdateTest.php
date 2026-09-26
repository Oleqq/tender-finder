<?php

use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\TenderSourceImportService;
use App\Tenders\RostenderTenderItem;
use App\Tenders\SourceFetchResult;

it('refreshes an existing tender when the source sends cleaner data', function () {
    $feed = SourceFeed::query()->create([
        'source' => 'rostender',
        'source_identifier' => 42,
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/42',
        'url_hash' => hash('sha256', 'refresh-feed'),
        'status' => 'manual_preview',
        'poll_interval_seconds' => 0,
    ]);
    $importer = app(TenderSourceImportService::class);
    $first = rostenderItemForRefresh('Технический заголовок', 'Старый этап', null, '2026-09-01');
    $clean = rostenderItemForRefresh('Понятный предмет закупки', 'Приём заявок', 728800, '2026-09-02');

    $importer->import($feed, new SourceFetchResult([$first]), 'rostender', false);
    Tender::query()->where('source', 'rostender')->sole()->update([
        'metadata' => [
            'delivery_place' => 'Адрес, сохранённый ранее',
            'enriched_at' => '2026-09-01T12:00:00+03:00',
        ],
    ]);
    $run = $importer->import($feed->fresh(), new SourceFetchResult([$clean]), 'rostender', false);

    expect($run->items_created)->toBe(0)
        ->and(Tender::query()->where('source', 'rostender')->sole()->title)->toBe('Понятный предмет закупки')
        ->and(Tender::query()->where('source', 'rostender')->sole()->description)->toBe('Приём заявок')
        ->and(Tender::query()->where('source', 'rostender')->sole()->budget_amount)->toBe('728800.00')
        ->and(Tender::query()->where('source', 'rostender')->sole()->metadata['delivery_place'])->toBe('Адрес, сохранённый ранее');
});

function rostenderItemForRefresh(string $title, string $stage, ?int $budgetAmount, string $updatedAt): RostenderTenderItem
{
    return RostenderTenderItem::fromDetail([
        'id' => 101,
        'url' => 'https://rostender.info/tender/101',
        'descr' => $title,
        'stage' => $stage,
        'price' => $budgetAmount === null ? [] : ['value' => $budgetAmount, 'currency' => 'RUB'],
        'updated_at' => $updatedAt,
    ]);
}
