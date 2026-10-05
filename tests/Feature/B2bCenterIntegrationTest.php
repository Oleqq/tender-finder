<?php

use App\Jobs\DeliverTelegramNotification;
use App\Jobs\MatchTender;
use App\Jobs\PollB2bCenterFeed;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\AccessService;
use App\Services\B2bCenterHtmlParser;
use App\Services\B2bCenterPollingDispatcher;
use App\Services\B2bCenterSearchApiParser;
use App\Services\B2bCenterSource;
use App\Services\TelegramBotClient;
use App\Services\TenderMatchingService;
use App\Services\TenderSourceImportService;
use App\Tenders\B2bCenterException;
use App\Tenders\SourceFetchResult;
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

it('collapses a repeated public B2B title into one readable heading', function () {
    $html = str_replace(
        'Закупка цинкооксидных поглотителей для загрузки системы',
        'Разработка сайта для перевозчика Разработка сайта для перевозчика',
        b2bCenterHtml(),
    );
    $item = app(B2bCenterHtmlParser::class)->parse($html, 'https://www.b2b-center.ru/market/')->items[0];

    expect($item->title)->toBe('Разработка сайта для перевозчика');
});

it('excludes nested organizer and category metadata from a B2B title and removes false matches on reimport', function () {
    $html = str_replace(
        'Ремонт производственного здания</div>',
        'Аренда ДГУ<div style="color:#888">4609184 Заказчик Строительство</div></div>',
        b2bCenterHtml(),
    );
    $result = app(B2bCenterHtmlParser::class)->parse($html, 'https://www.b2b-center.ru/market/');
    expect($result->items[1]->title)->toBe('Аренда ДГУ');

    $user = User::factory()->create();
    $query = SearchQuery::query()->create([
        'user_id' => $user->id, 'name' => 'Строительство',
        'keywords' => ['строительство'], 'status' => 'active',
        'filters' => ['relevance' => ['match_mode' => 'phrase']],
    ]);
    $tender = Tender::query()->create([
        'source' => 'b2b_center', 'external_id' => '4609184',
        'canonical_url' => $result->items[1]->canonicalUrl,
        'canonical_url_hash' => $result->items[1]->urlHash,
        'title' => 'Аренда ДГУ 4609184 Заказчик Строительство',
        'currency' => 'RUB',
    ]);
    TenderQueryMatch::query()->create([
        'search_query_id' => $query->id, 'tender_id' => $tender->id,
        'match_reasons' => ['keywords' => ['строительство']], 'matched_at' => now(),
    ]);
    TenderUserState::query()->create([
        'user_id' => $user->id, 'tender_id' => $tender->id,
        'status' => 'favorite',
    ]);

    app(TenderSourceImportService::class)->import(b2bCenterFeed(), $result, 'b2b_center', false);

    expect($tender->fresh()->title)->toBe('Аренда ДГУ')
        ->and(TenderQueryMatch::query()->where('tender_id', $tender->id)->exists())->toBeFalse()
        ->and(TenderUserState::query()->where('tender_id', $tender->id)->exists())->toBeTrue();
});

it('rejects a changed B2B-Center catalog layout instead of accepting an empty snapshot', function () {
    expect(fn () => app(B2bCenterHtmlParser::class)->parse('<html><body>Changed</body></html>', 'https://www.b2b-center.ru/market/'))
        ->toThrow(B2bCenterException::class, 'catalog_layout_changed');
});

it('requests only the configured public catalog without account credentials', function () {
    Http::fake(fn () => Http::response(b2bCenterApiFixture()));
    $feed = b2bCenterFeed();

    $result = app(B2bCenterSource::class)->fetch($feed);

    expect($result->items)->toHaveCount(2);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.b2b-center.ru/site/api/v1/market-search/?')
        && $request['tab'] === 'actual' && $request['page'] == 1
        && $request->hasHeader('User-Agent', 'TenderFinder tests')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('Cookie'));
});

it('follows the current catalog pagination up to the configured bound', function () {
    config()->set('tender.b2b_center.pages_per_poll', 2);
    Http::fake(function (Request $request) {
        $page = (int) $request['page'];
        $data = b2bCenterApiFixture();
        $data['page_count'] = 3;
        $data['tabs'][0]['count'] = 3;
        $data['trades'] = [$data['trades'][0]];
        $data['trades'][0]['trade_id'] = 4616410 + $page;
        $data['trades'][0]['url'] = '/market/razrabotka-saita/tender-'.(4616410 + $page).'/';

        return Http::response($data);
    });

    $result = app(B2bCenterSource::class)->fetch(b2bCenterFeed());

    expect($result->items)->toHaveCount(2)
        ->and(array_map(fn ($item) => $item->externalId, $result->items))->toBe(['4616411', '4616412']);
    Http::assertSentCount(2);
});

it('stops when the guest catalog repeats the first page', function () {
    config()->set('tender.b2b_center.pages_per_poll', 5);
    $data = b2bCenterApiFixture();
    $data['page_count'] = 100;
    Http::fake(fn () => Http::response($data));

    $result = app(B2bCenterSource::class)->fetch(b2bCenterFeed());

    expect($result->items)->toHaveCount(2);
    Http::assertSentCount(2);
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
    Http::fake(fn () => Http::response(b2bCenterApiFixture()));
    $feed = b2bCenterFeed();

    (new PollB2bCenterFeed($feed->id))->handle(app(B2bCenterSource::class), app(TenderSourceImportService::class));

    expect(Tender::query()->where('source', 'b2b_center')->count())->toBe(2)
        ->and($feed->fresh()->initialized_at)->not->toBeNull();
    Queue::assertPushed(MatchTender::class, 2);
    Queue::assertPushed(MatchTender::class, fn (MatchTender $job): bool => $job->queueNotifications === false);
});

it('parses the current public JSON search and rejects a broken response', function () {
    $parser = app(B2bCenterSearchApiParser::class);
    $result = $parser->parse(b2bCenterApiFixture());

    expect($result['items'])->toHaveCount(2)
        ->and($result['items'][0]->canonicalUrl)->toBe('https://www.b2b-center.ru/market/razrabotka-saita/tender-4616411/')
        ->and($result['items'][0]->title)->toBe('Разработка сайта для перевозчика')
        ->and($result['items'][0]->metadata['customer'])->toBe('ООО «Заказчик»');
    expect(fn () => $parser->parse(['trades' => []]))->toThrow(B2bCenterException::class, 'catalog_layout_changed');
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

it('queues a newly discovered B2B match only for the linked user and delivers it once', function () {
    Queue::fake();
    $first = User::factory()->create(['telegram_id' => 'recipient-one']);
    $other = User::factory()->create(['telegram_id' => 'recipient-two']);
    foreach ([$first, $other] as $user) {
        Entitlement::query()->create([
            'user_id' => $user->id, 'code' => 'active_queries', 'status' => 'active',
            'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
    }
    $feed = b2bCenterFeed();
    $query = SearchQuery::query()->create([
        'user_id' => $first->id, 'name' => 'Закупка',
        'keywords' => ['закупка'], 'status' => 'active',
        'monitoring_started_at' => now()->subMinute(),
    ]);
    SearchQuery::query()->create([
        'user_id' => $other->id, 'name' => 'Закупка',
        'keywords' => ['закупка'], 'status' => 'active',
        'monitoring_started_at' => now()->subMinute(),
    ]);
    SourceFeedSearchQuery::query()->create(['source_feed_id' => $feed->id, 'search_query_id' => $query->id]);
    $importer = app(TenderSourceImportService::class);
    $importer->import($feed, new SourceFetchResult([], 0), 'b2b_center', false);
    $result = app(B2bCenterHtmlParser::class)->parse(b2bCenterHtml(), $feed->canonical_url);
    $importer->import($feed->fresh(), $result, 'b2b_center');
    $tender = Tender::query()->where('external_id', '4616894')->sole();
    (new MatchTender($tender->id))->handle(app(TenderMatchingService::class));

    $delivery = NotificationDelivery::query()->where('type', 'tender_card')->sole();
    expect($delivery->user_id)->toBe($first->id);
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldReceive('sendNotification')->once()->with('recipient-one', Mockery::on(
        fn (string $text): bool => str_contains($text, 'Закупка цинкооксидных поглотителей'),
    ));
    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));
    expect($delivery->fresh()->status->value)->toBe('sent');

    $importer->import($feed->fresh(), $result, 'b2b_center');
    (new MatchTender($tender->id))->handle(app(TenderMatchingService::class));
    expect(NotificationDelivery::query()->where('type', 'tender_card')->count())->toBe(1);
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

/** @return array<string, mixed> */
function b2bCenterApiFixture(): array
{
    return [
        'current_tab' => 'actual',
        'page_count' => 1,
        'tabs' => [['type' => 'actual', 'count' => 2]],
        'trades' => [
            [
                'trade_id' => 4616411,
                'description' => 'Разработка <mark>сайта</mark> для перевозчика Разработка <mark>сайта</mark> для перевозчика',
                'url' => '/market/razrabotka-saita/tender-4616411/#tracking',
                'date_published' => '05.10.2026 10:00',
                'date_actual' => '15.10.2026 12:00',
                'org_name_short' => 'ООО &laquo;Заказчик&raquo;',
                'price' => '100 000,00 руб.',
                'region' => 'Москва',
            ],
            [
                'trade_id' => 4616412,
                'description' => 'Закупка оборудования',
                'url' => '/app/market-next/zakupka-oborudovaniya/tender-4616412/',
                'date_published' => '05.10.2026 11:00',
                'date_actual' => '16.10.2026 12:00',
                'org_name_short' => 'АО «Заказчик»',
                'price' => 'Без указания цены',
                'region' => '',
            ],
        ],
    ];
}
