<?php

use App\Models\SourceFeed;
use App\Models\SourceFeedItem;
use App\Models\Tender;
use App\Services\TenderSourceImportService;
use App\Tenders\B2bCenterTenderItem;
use App\Tenders\RostenderTenderItem;
use App\Tenders\SourceFetchResult;
use Carbon\CarbonImmutable;

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

it('stores source instants in UTC without shifting them after a database reload or refresh', function () {
    $feed = SourceFeed::query()->create([
        'source' => 'b2b_center',
        'canonical_url' => 'https://www.b2b-center.ru/market/',
        'url_hash' => hash('sha256', 'timezone-feed'),
        'status' => 'manual_preview',
        'poll_interval_seconds' => 0,
    ]);
    $item = new B2bCenterTenderItem(
        externalId: 'tz-1', regNumber: null,
        canonicalUrl: 'https://www.b2b-center.ru/market/tender-tz-1/',
        urlHash: hash('sha256', 'tz-1'), title: 'Срок подачи', summary: null,
        publishedAt: CarbonImmutable::parse('2026-10-05T10:00:00+03:00'),
        contentHash: 'timezone',
        deadlineAt: CarbonImmutable::parse('2026-10-09T12:00:00+03:00'),
        externalUpdatedAt: CarbonImmutable::parse('2026-10-05T11:00:00+03:00'),
        detailsFetchedAt: CarbonImmutable::parse('2026-10-05T12:00:00+03:00'),
    );
    $importer = app(TenderSourceImportService::class);
    foreach ([1, 2] as $poll) {
        $importer->import($feed, new SourceFetchResult([$item]), 'b2b_center', false);
        $tender = Tender::query()->where('external_id', 'tz-1')->sole();
        expect($tender->deadline_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-09 09:00:00')
            ->and($tender->deadline_at?->setTimezone('Europe/Moscow')->format('H:i'))->toBe('12:00')
            ->and($tender->published_at?->utc()->format('H:i'))->toBe('07:00')
            ->and($tender->external_updated_at?->utc()->format('H:i'))->toBe('08:00')
            ->and($tender->details_fetched_at?->utc()->format('H:i'))->toBe('09:00')
            ->and(SourceFeedItem::query()->where('source_feed_id', $feed->id)->sole()->published_at?->utc()->format('H:i'))->toBe('07:00');
    }
});
