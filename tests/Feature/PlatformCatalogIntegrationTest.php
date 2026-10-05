<?php

use App\Jobs\MatchTender;
use App\Jobs\PollPlatformCatalogFeed;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlatformCatalogFeedService;
use App\Services\PlatformCatalogParser;
use App\Services\PlatformCatalogPollingDispatcher;
use App\Services\PlatformCatalogSource;
use App\Services\TenderMatchingService;
use App\Services\TenderSourceImportService;
use App\Tenders\PlatformCatalogException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'tender.platform_catalog.roseltorg.enabled' => true,
        'tender.platform_catalog.rts_tender.enabled' => true,
        'tender.platform_catalog.sber_ast_catalog.enabled' => true,
        'tender.platform_catalog.pages_per_poll' => 2,
        'tender.platform_catalog.poll_interval_seconds' => 1800,
        'tender.platform_catalog.request_timeout_seconds' => 15,
        'tender.platform_catalog.user_agent' => 'TenderFinder tests',
    ]);
});

it('parses operator-specific cards and rejects a changed catalog', function () {
    $ros = app(PlatformCatalogParser::class)->parse(platformCatalogHtml('roseltorg'), 'roseltorg');
    $rts = app(PlatformCatalogParser::class)->parse(platformCatalogHtml('rts_tender'), 'rts_tender');
    $sber = app(PlatformCatalogParser::class)->parse(platformCatalogHtml('sber_ast_catalog'), 'sber_ast_catalog');

    expect($ros->items)->toHaveCount(1)
        ->and($ros->items[0]->regNumber)->toBe('057270000012601050')
        ->and($ros->items[0]->budgetAmount)->toBe('6051106.23')
        ->and($ros->items[0]->canonicalUrl)->toBe('https://www.b2b-center.ru/search/number/l057270000012601050-1/')
        ->and($ros->items[0]->metadata['platform'])->toBe('Росэлторг')
        ->and($rts->items)->toHaveCount(1)
        ->and($rts->items[0]->regNumber)->toBe('10727596')
        ->and($rts->items[0]->metadata['platform'])->toBe('РТС-Тендер')
        ->and($sber->items)->toHaveCount(1)
        ->and($sber->items[0]->metadata['platform'])->toBe('Сбер АСТ')
        ->and($sber->items[0]->metadata['platform_url'])->toContain('zakupki.gov.ru');

    expect(fn () => app(PlatformCatalogParser::class)->parse('<h1>Проверяем ваш браузер</h1>', 'rts_tender'))
        ->toThrow(PlatformCatalogException::class, 'catalog_layout_changed');
});

it('fetches bounded public pages, imports platform tenders, and suppresses initial notifications', function () {
    Queue::fake();
    $user = User::factory()->create();
    $query = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Оборудование',
        'keywords' => ['оборудование'],
        'status' => 'active',
    ]);
    $url = PlatformCatalogFeedService::URLS['rts_tender'];
    Http::fake([
        $url => Http::response(platformCatalogHtml('rts_tender')),
        $url.'?Page=2' => Http::response(platformCatalogHtml('rts_tender', 'l15256735-3-9-1')),
    ]);
    $feed = app(PlatformCatalogFeedService::class)->configuredFeed('rts_tender');

    (new PollPlatformCatalogFeed($feed->id))->handle(app(PlatformCatalogSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'rts_tender')->count())->toBe(2)
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, 2);
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
    app(TenderMatchingService::class)->matchTender(Tender::query()->where('source', 'rts_tender')->firstOrFail(), false);
    expect(TenderQueryMatch::query()->where('search_query_id', $query->id)->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $url.'?Page=2'
        && $request->hasHeader('User-Agent', 'TenderFinder tests')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Cookie'));
});

it('imports Sber AST catalog cards separately from the direct Sber source', function () {
    Queue::fake();
    config()->set('tender.platform_catalog.pages_per_poll', 1);
    $url = PlatformCatalogFeedService::URLS['sber_ast_catalog'];
    Http::fake([$url => Http::response(platformCatalogHtml('sber_ast_catalog'))]);
    $feed = app(PlatformCatalogFeedService::class)->configuredFeed('sber_ast_catalog');

    (new PollPlatformCatalogFeed($feed->id))->handle(app(PlatformCatalogSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'sber_ast_catalog')->count())->toBe(1)
        ->and(Tender::query()->where('source', 'sber_ast')->count())->toBe(0)
        ->and($feed->fresh()->last_success_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
});

it('dispatches each enabled platform only when its feed is due', function () {
    Queue::fake();
    $dispatcher = app(PlatformCatalogPollingDispatcher::class);

    expect($dispatcher->dispatchDueFeed('roseltorg'))->toBeTrue()
        ->and($dispatcher->dispatchDueFeed('roseltorg'))->toBeFalse()
        ->and($dispatcher->dispatchDueFeed('rts_tender'))->toBeTrue();
    expect($dispatcher->dispatchDueFeed('sber_ast_catalog'))->toBeTrue();
    Queue::assertPushed(PollPlatformCatalogFeed::class, 3);
    expect(SourceFeed::query()->whereIn('source', ['roseltorg', 'rts_tender', 'sber_ast_catalog'])->count())->toBe(3);
});

it('retries a transient connection failure within the same poll', function () {
    config()->set('tender.platform_catalog.pages_per_poll', 1);
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        if ($attempts === 1) {
            throw new ConnectionException('temporary timeout');
        }

        return Http::response(platformCatalogHtml('rts_tender'));
    });

    $feed = app(PlatformCatalogFeedService::class)->configuredFeed('rts_tender');
    $result = app(PlatformCatalogSource::class)->fetch($feed);

    expect($attempts)->toBe(2)->and($result->items)->toHaveCount(1);
});

function platformCatalogHtml(string $source, string $cardId = ''): string
{
    $ros = $source === 'roseltorg';
    $sber = $source === 'sber_ast_catalog';
    $id = $cardId !== '' ? $cardId : ($ros ? 'l057270000012601050-1' : ($sber ? 'l0325300000726000310-1' : 'l15256734-3-9-1'));
    $name = $ros ? 'АО «ЕЭТП»' : ($sber ? 'АО «Сбербанк-АСТ»' : 'РТС-тендер');
    $number = $ros ? '057270000012601050' : ($sber ? '0325300000726000310' : '10727596');
    $native = $ros
        ? 'https://zakupki.gov.ru/epz/order/notice/ea615/view/common-info.html?regNumber='.$number
        : ($sber ? 'http://zakupki.gov.ru/epz/order/notice/view/common-info.html?regNumber='.$number
            : 'https://market-lk.rts-tender.ru/supplier/lk/Handlers/EntityUrlHandler.ashx?Id='.$number);
    $native = htmlspecialchars($native, ENT_QUOTES);

    return <<<HTML
<!doctype html><html><body><h1>Закупки площадки {$name}</h1>
<div class="cards" itemprop="itemListElement">
<div itemprop="item"><meta itemprop="name" content="Поставка оборудования для школы" />
<meta itemprop="startDate" content="2026-10-02T14:55:14+03:00" />
<meta itemprop="endDate" content="2026-10-12T09:05:00+03:00" />
<div class="card-item"><meta itemprop="url" content="https://www.b2b-center.ru/search/number/{$id}/" />
<meta itemprop="price" content="6&#xA0;051&#xA0;106,23" />
<div class="card-item__about"><a href="{$native}">Закупка №{$number}</a></div>
<div class="card-item__organization-name">Школа № 1</div></div></div></div>
</body></html>
HTML;
}
