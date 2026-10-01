<?php

use App\Jobs\MatchTender;
use App\Jobs\PollB2bCenterFeed;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\B2bCenterHtmlParser;
use App\Services\B2bCenterPollingDispatcher;
use App\Services\B2bCenterSource;
use App\Services\TenderSourceImportService;
use App\Tenders\B2bCenterException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set([
        'tender.b2b_center.enabled' => true,
        'tender.b2b_center.catalog_url' => 'https://www.b2b-center.ru/market/',
        'tender.b2b_center.poll_interval_seconds' => 3600,
        'tender.b2b_center.request_timeout_seconds' => 15,
        'tender.b2b_center.user_agent' => 'TenderFinder tests',
    ]);
});

it('parses public B2B-Center catalog rows and strips tracking fragments', function () {
    $result = app(B2bCenterHtmlParser::class)->parse(b2bCenterHtml(), 'https://www.b2b-center.ru/market/');

    expect($result->items)->toHaveCount(2);
    $item = $result->items[0];

    expect($item->externalId)->toBe('4616894')
        ->and($item->regNumber)->toBe('4616894')
        ->and($item->canonicalUrl)->toBe('https://www.b2b-center.ru/market/zakupka-poglotitelei/tender-4616894/')
        ->and($item->title)->toBe('Закупка цинкооксидных поглотителей для загрузки системы')
        ->and($item->publishedAt?->format('Y-m-d H:i:sP'))->toBe('2026-09-27 20:59:00+03:00')
        ->and($item->deadlineAt?->format('Y-m-d H:i:sP'))->toBe('2026-10-12 13:01:00+03:00')
        ->and($item->metadata['customer'])->toBe('ООО УК «МЕТАЛЛОИНВЕСТ»')
        ->and($item->metadata['category'])->toBe('Химия')
        ->and($item->metadata['b2b_center']['procedure_type'])->toBe('Запрос цен');

    expect($result->items[1]->canonicalUrl)
        ->toBe('https://www.b2b-center.ru/app/market-next/remont/tender-4609184/');
});

it('rejects a changed B2B-Center catalog layout instead of accepting an empty snapshot', function () {
    expect(fn () => app(B2bCenterHtmlParser::class)->parse('<html><body>Changed</body></html>', 'https://www.b2b-center.ru/market/'))
        ->toThrow(B2bCenterException::class, 'catalog_layout_changed');
});

it('requests only the configured public catalog without account credentials', function () {
    Http::fake([
        'https://www.b2b-center.ru/market/' => Http::response(b2bCenterHtml()),
    ]);
    $feed = b2bCenterFeed();

    $result = app(B2bCenterSource::class)->fetch($feed);

    expect($result->items)->toHaveCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === $feed->canonical_url
        && $request->hasHeader('User-Agent', 'TenderFinder tests')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Cookie'));
});

it('never requests a non-official URL even when it is present in configuration', function () {
    Http::fake();
    config()->set('tender.b2b_center.catalog_url', 'https://example.test/market/');
    $feed = SourceFeed::query()->create([
        'source' => 'b2b_center',
        'canonical_url' => 'https://example.test/market/',
        'url_hash' => hash('sha256', 'b2b_center:https://example.test/market/'),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
    ]);

    expect(fn () => app(B2bCenterSource::class)->fetch($feed))
        ->toThrow(B2bCenterException::class, 'feed_not_configured');
    Http::assertNothingSent();
});

it('imports B2B-Center cards and suppresses notifications for the initial catalog snapshot', function () {
    Queue::fake();
    Http::fake([
        'https://www.b2b-center.ru/market/' => Http::response(b2bCenterHtml()),
    ]);
    $feed = b2bCenterFeed();

    (new PollB2bCenterFeed($feed->id))->handle(app(B2bCenterSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'b2b_center')->count())->toBe(2)
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, 2);
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
});

it('creates and dispatches the configured B2B-Center catalog when due', function () {
    Queue::fake();

    expect(app(B2bCenterPollingDispatcher::class)->dispatchDueFeed())->toBeTrue();

    $feed = SourceFeed::query()->where('source', 'b2b_center')->sole();
    expect($feed->canonical_url)->toBe('https://www.b2b-center.ru/market/')
        ->and($feed->next_poll_at)->not->toBeNull();
    Queue::assertPushed(PollB2bCenterFeed::class, fn (PollB2bCenterFeed $job): bool => $job->feedId === $feed->id);

    expect(app(B2bCenterPollingDispatcher::class)->dispatchDueFeed())->toBeFalse();
});

it('recognizes the observed public empty search but rejects a blank table and blocking page', function () {
    $parser = app(B2bCenterHtmlParser::class);
    $html = '<div class="search-results empty_results"><div class="h2">По запросу “test” сейчас нет актуальных торговых процедур.</div></div>';
    expect($parser->parse($html, 'https://www.b2b-center.ru/market/')->items)->toBe([]);
    foreach (['<table class="search-results"><tbody></tbody></table>', '<html>Service unavailable</html>', '<div class="empty_results">captcha</div>'] as $invalid) {
        expect(fn () => $parser->parse($invalid, 'https://www.b2b-center.ru/market/'))->toThrow(B2bCenterException::class);
    }
});

it('rejects injected parameters and alternate hosts for a keyword feed', function () {
    Http::fake();
    $feed = b2bCenterFeed();
    foreach (['https://www.b2b-center.ru.evil.test/market/?f_keyword=test&searching=1', 'https://www.b2b-center.ru/market/?f_keyword=test&searching=1&show=archive', 'https://www.b2b-center.ru/market/?f_keyword=test&searching=1#fragment'] as $url) {
        $feed->canonical_url = $url;
        expect(fn () => app(B2bCenterSource::class)->fetch($feed))->toThrow(B2bCenterException::class, 'feed_not_configured');
    }
    Http::assertNothingSent();
});

it('rematches previously imported public cards without repeating historical notifications', function () {
    Queue::fake();
    $feed = b2bCenterFeed();
    $result = app(B2bCenterHtmlParser::class)->parse(b2bCenterHtml(), $feed->canonical_url);
    $importer = app(TenderSourceImportService::class);
    $importer->import($feed, $result, 'b2b_center', false);
    $importer->import($feed, $result, 'b2b_center');
    expect(Tender::query()->count())->toBe(2);
    Queue::assertPushed(MatchTender::class, 2);
    Queue::assertNotPushed(MatchTender::class, fn (MatchTender $job) => $job->queueNotifications);
});

function b2bCenterFeed(): SourceFeed
{
    $url = 'https://www.b2b-center.ru/market/';

    return SourceFeed::query()->create([
        'source' => 'b2b_center',
        'canonical_url' => $url,
        'url_hash' => hash('sha256', 'b2b_center:'.$url),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
    ]);
}

function b2bCenterHtml(): string
{
    return <<<'HTML'
<!doctype html>
<html><body>
<table class="table table-hover table-filled search-results">
<thead><tr><th>Название процедуры</th><th>Организатор</th><th>Опубликовано</th><th>Актуально до</th><th></th></tr></thead>
<tbody>
<tr>
<td><small>Химия</small><br><a class="search-results-title visited" href="/market/zakupka-poglotitelei/tender-4616894/#btid=2&amp;tsid=4616894004">Запрос цен № 4616894<div class="search-results-title-desc">Закупка цинкооксидных поглотителей для загрузки системы</div></a></td>
<td><a href="/firms/metalloinvest/">ООО УК «МЕТАЛЛОИНВЕСТ»</a></td><td>27.09.2026 20:59</td><td>12.10.2026 13:01</td><td></td>
</tr>
<tr>
<td><small>Строительство</small><br><a class="search-results-title" href="/app/market-next/remont/tender-4609184/#btid=2">Запрос предложений № 4609184<div class="search-results-title-desc">Ремонт производственного здания</div></a></td>
<td>АО «Заказчик»</td><td>27.09.2026 18:31</td><td>02.10.2026 12:00</td><td></td>
</tr>
</tbody></table>
</body></html>
HTML;
}
