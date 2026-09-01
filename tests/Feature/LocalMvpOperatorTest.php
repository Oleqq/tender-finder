<?php

use App\Enums\AccessState;
use App\Enums\TenderUserStatus;
use App\Enums\UserRole;
use App\Jobs\MatchTender;
use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\AccessService;
use App\Services\LocalMvpSearchSnapshotService;
use App\Services\LocalMvpTenderWorkspaceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('tender.local_mvp_operator.enabled', true);
    config()->set('tender.rss.global_min_interval_milliseconds', 0);
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('opens the local MVP workspace as the local-only super admin', function () {
    $this->get('/local/mvp-operator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MvpWorkspace')
            ->has('currentTenders')
            ->has('currentSearch')
            ->has('historyTenders')
            ->has('savedSearches')
            ->where('eisRegions.0.code', '01000000000'));

    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();

    $this->assertAuthenticatedAs($operator);
    expect($operator->role)->toBe(UserRole::SuperAdmin)
        ->and($operator->telegram_id)->toBeNull();

    $access = app(AccessService::class)->snapshotFor($operator);
    expect($access->state)->toBe(AccessState::Active)
        ->and($access->planCode)->toBe('local_mvp_operator')
        ->and($access->activeQueryLimit)->toBe(20);

});

it('keeps the local operator entry point disabled without its explicit flag', function () {
    config()->set('tender.local_mvp_operator.enabled', false);

    $this->get('/local/mvp-operator')->assertNotFound();
    $this->assertGuest();
});

it('imports an open preview manually for the local operator without queuing notifications', function () {
    Queue::fake();
    Http::fake([
        'https://www.tenderguru.ru/api2.3/export*' => Http::response(<<<'JSON'
[
  {"Total":"1"},
  {
    "ID":"9990002",
    "Date":"26-08-2026",
    "TenderName":"Техническая поддержка корпоративного сайта",
    "Category":"IT",
    "Region":"Москва",
    "Price":"150000",
    "EndTime":"03-09-2026",
    "TenderNumOuter":"01234567890123456788",
    "Fz":"44",
    "searchFragmentXML":{"fragment":["Техническая [B]поддержка[/B] сайта"]}
  }
]
JSON),
    ]);

    $this->get('/local/mvp-operator')->assertOk();

    $this->postJson('/local/mvp/tenderguru-preview', ['query' => 'поддержка сайта'])
        ->assertOk()
        ->assertJsonPath('preview.items_seen', 1)
        ->assertJsonPath('preview.items_matched', 1)
        ->assertJsonPath('preview.items_created', 1)
        ->assertJsonPath('preview.matches_created', 0)
        ->assertJsonPath('tenders.0.status', 'new')
        ->assertJsonCount(1, 'tenders');

    Http::assertSentCount(1);
    Queue::assertNotPushed(MatchTender::class);
    expect(TenderQueryMatch::query()->count())->toBe(0);
});

it('creates an EIS RSS search from a phrase and imports it without activating monitoring', function () {
    Queue::fake();
    $fixture = file_get_contents(base_path('tests/Fixtures/eis-rss-initial.xml'));
    expect($fixture)->not->toBeFalse();

    Http::fake([
        'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html*' => Http::response(
            $fixture,
            200,
            ['Content-Type' => 'application/rss+xml'],
        ),
    ]);

    $this->get('/local/mvp-operator')->assertOk();

    $response = $this->postJson('/local/mvp/eis-rss-preview', [
        'query' => 'поддержка сайта',
        'pages' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('preview.items_seen', 1)
        ->assertJsonPath('preview.items_matched', 1)
        ->assertJsonPath('preview.items_created', 1)
        ->assertJsonPath('preview.pages_requested', 1)
        ->assertJsonPath('preview.pages_loaded', 1)
        ->assertJsonPath('preview.partially_loaded', false)
        ->assertJsonPath('tenders.0.status', 'new')
        ->assertJsonCount(1, 'tenders');

    $tenderId = $response->json('tenders.0.id');

    $this->postJson("/local/mvp/tenders/{$tenderId}/status", ['status' => 'favorite'])
        ->assertOk()
        ->assertJsonPath('tender.status', 'favorite');

    $this->get('/local/mvp-operator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentSearch.query', 'поддержка сайта')
            ->where('currentTenders.0.id', $tenderId)
            ->where('currentTenders.0.status', 'favorite'));

    $this->assertDatabaseHas('source_feeds', [
        'status' => 'manual_preview',
        'poll_interval_seconds' => 0,
    ]);
    $this->assertDatabaseHas('tenders', ['id' => $tenderId, 'source' => 'eis_rss']);
    expect(SourceFeed::query()->where('status', 'active')->count())->toBe(0);
    Queue::assertNotPushed(MatchTender::class);
});

it('combines a bounded sequence of EIS RSS pages into one current result', function () {
    Queue::fake();
    $pages = [
        1 => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><item>
<title>Поддержка сайта, извещение 01234567890123456781</title>
<link>https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456781</link>
<description><![CDATA[Поддержка сайта]]></description>
</item></channel></rss>
XML,
        2 => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><item>
<title>Поддержка сайта, извещение 01234567890123456782</title>
<link>https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456782</link>
<description><![CDATA[Поддержка сайта]]></description>
</item></channel></rss>
XML,
        3 => '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel /></rss>',
    ];
    $requestedPages = [];

    Http::fake(function ($request) use (&$requestedPages, $pages) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
        $page = (int) ($parameters['pageNumber'] ?? 0);
        $requestedPages[] = $page;

        return Http::response(
            $pages[$page] ?? $pages[3],
            200,
            ['Content-Type' => 'application/rss+xml'],
        );
    });

    $this->get('/local/mvp-operator')->assertOk();

    $this->postJson('/local/mvp/eis-rss-preview', [
        'query' => 'поддержка сайта',
        'pages' => 3,
    ])
        ->assertOk()
        ->assertJsonPath('preview.items_seen', 2)
        ->assertJsonPath('preview.items_matched', 2)
        ->assertJsonPath('preview.items_created', 2)
        ->assertJsonPath('preview.pages_requested', 3)
        ->assertJsonPath('preview.pages_loaded', 3)
        ->assertJsonPath('preview.partially_loaded', false)
        ->assertJsonCount(2, 'tenders');

    expect($requestedPages)->toBe([1, 2, 3]);
    Http::assertSentCount(3);
    Queue::assertNotPushed(MatchTender::class);
});

it('applies verified EIS filters when it builds an automatic RSS search', function () {
    Queue::fake();
    $fixture = file_get_contents(base_path('tests/Fixtures/eis-rss-initial.xml'));
    expect($fixture)->not->toBeFalse();

    Http::fake([
        'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html*' => Http::response(
            $fixture,
            200,
            ['Content-Type' => 'application/rss+xml'],
        ),
    ]);

    $this->get('/local/mvp-operator')->assertOk();

    $this->postJson('/local/mvp/eis-rss-preview', [
        'query' => 'поддержка сайта',
        'match_mode' => 'any',
        'minus_keywords' => ['строительство'],
        'pages' => 1,
        'law_44' => true,
        'stage_application' => true,
        'stage_commission' => false,
        'stage_completed' => true,
        'stage_cancelled' => false,
        'joint_purchase' => true,
        'union_state_budget' => true,
        'smp_sono' => true,
        'budget_from' => '100000',
        'budget_to' => '750000.50',
        'published_from' => '2026-08-01',
        'published_to' => '2026-08-27',
    ])
        ->assertOk()
        ->assertJsonPath('preview.items_seen', 1)
        ->assertJsonPath('tenders.0.match_reason.mode', 'any')
        ->assertJsonPath('tenders.0.match_reason.matched_terms.0', 'поддержка')
        ->assertJsonPath(
            'tenders.0.match_reason.minus_keywords_checked.0',
            'строительство',
        );

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return ($parameters['fz44'] ?? null) === 'on'
            && ! isset($parameters['fz223'])
            && ($parameters['af'] ?? null) === 'on'
            && ! isset($parameters['ca'])
            && ($parameters['pc'] ?? null) === 'on'
            && ! isset($parameters['pa'])
            && ($parameters['jointPurchase'] ?? null) === 'on'
            && ($parameters['budgetUnionState'] ?? null) === 'on'
            && ($parameters['procurementSMPAndSONO'] ?? null) === 'on'
            && ($parameters['priceFromGeneral'] ?? null) === '100000'
            && ($parameters['priceToGeneral'] ?? null) === '750000.5'
            && ($parameters['currencyIdGeneral'] ?? null) === '1'
            && ($parameters['publishDateFrom'] ?? null) === '01.08.2026'
            && ($parameters['publishDateTo'] ?? null) === '27.08.2026';
    });
});

it('searches the official EIS OKPD2 catalog without exposing its raw response', function () {
    Http::fake([
        'https://zakupki.gov.ru/epz/api/nsi/okpd2/search.html*' => Http::response([
            'children' => [[
                'key' => 8879022,
                'code' => '62.01.11',
                'name' => 'Услуги по проектированию программного обеспечения',
                'children' => [[
                    'key' => 8890621,
                    'code' => '62.01.11.000',
                    'name' => 'Разработка программного обеспечения',
                    'children' => null,
                ]],
            ]],
        ]),
    ]);

    $this->get('/local/mvp-operator')->assertOk();

    $this->getJson('/local/mvp/eis/okpd2-options?search=62.01.11.000')
        ->assertOk()
        ->assertJsonPath('options.0.id', '8890621')
        ->assertJsonPath('options.0.code', '62.01.11.000')
        ->assertJsonMissingPath('options.0.children');
});

it('rejects an automatic EIS search without a procurement stage', function () {
    Http::fake();
    $this->get('/local/mvp-operator')->assertOk();

    $this->postJson('/local/mvp/eis-rss-preview', [
        'query' => 'поддержка сайта',
        'stage_application' => false,
        'stage_commission' => false,
        'stage_completed' => false,
        'stage_cancelled' => false,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stage_application');

    Http::assertNothingSent();
});

it('exposes source-provided EIS card facts in the local workspace', function () {
    $this->get('/local/mvp-operator')->assertOk();
    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();

    $tender = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => '01234567890123456789',
        'reg_number' => '01234567890123456789',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456789',
        'canonical_url_hash' => hash('sha256', 'workspace-eis-card-facts'),
        'title' => 'Разработка официального сайта',
        'description' => 'Электронный аукцион · 44-ФЗ',
        'budget_amount' => '728800.00',
        'currency' => 'RUB',
        'published_at' => '2026-08-24 10:00:00',
        'metadata' => [
            'customer' => 'Тестовый заказчик',
            'category' => 'Электронный аукцион',
            'procurement_law' => '44',
        ],
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'query' => 'Разработка сайта',
        'source' => 'eis_rss',
        'tender_ids' => [$tender->id],
    ]);

    $this->get('/local/mvp-operator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('historyTenders.0.reg_number', '01234567890123456789')
            ->where('historyTenders.0.customer', 'Тестовый заказчик')
            ->where('historyTenders.0.category', 'Электронный аукцион')
            ->where('historyTenders.0.procurement_law', '44'));
});

it('saves and returns EIS conditions with a local MVP saved search', function () {
    $this->get('/local/mvp-operator')->assertOk();

    $this->postJson('/queries', [
        'name' => 'Разработка сайта',
        'keywords' => ['разработка', 'сайта'],
        'minus_keywords' => ['строительство', 'бумажная продукция'],
        'filters' => [
            'relevance' => ['match_mode' => 'exact'],
            'source' => [
                'law_44' => true,
                'law_223' => false,
                'stage_application' => true,
                'stage_commission' => true,
                'stage_completed' => false,
                'stage_cancelled' => false,
                'joint_purchase' => true,
                'smp_sono' => true,
                'budget_from' => '100000',
                'budget_to' => '750000',
                'published_from' => '2026-08-01',
                'published_to' => '2026-08-27',
                'regions' => [[
                    'code' => '77000000000',
                    'name' => 'г. Москва',
                ]],
                'okpd2' => [[
                    'id' => '8890621',
                    'code' => '62.01.11.000',
                    'name' => 'Разработка программного обеспечения',
                ]],
                'okpd2_with_nested' => true,
                'pages' => 1,
            ],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('query.minus_keywords.0', 'строительство')
        ->assertJsonPath('query.filters.relevance.match_mode', 'exact')
        ->assertJsonPath('query.filters.source.law_44', true)
        ->assertJsonPath('query.filters.source.stage_application', true)
        ->assertJsonPath('query.filters.source.stage_completed', false)
        ->assertJsonPath('query.filters.source.joint_purchase', true)
        ->assertJsonPath('query.filters.source.budget_from', '100000')
        ->assertJsonPath('query.filters.source.published_to', '2026-08-27')
        ->assertJsonPath('query.filters.source.regions.0.code', '77000000000')
        ->assertJsonPath('query.filters.source.okpd2.0.id', '8890621')
        ->assertJsonPath('query.filters.source.pages', 1);

    $this->get('/local/mvp-operator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('savedSearches.0.name', 'Разработка сайта')
            ->where('savedSearches.0.minus_keywords.1', 'бумажная продукция')
            ->where('savedSearches.0.filters.relevance.match_mode', 'exact')
            ->where('savedSearches.0.filters.source.law_44', true)
            ->where('savedSearches.0.filters.source.stage_commission', true)
            ->where('savedSearches.0.filters.source.smp_sono', true)
            ->where('savedSearches.0.filters.source.budget_to', '750000')
            ->where('savedSearches.0.filters.source.regions.0.name', 'г. Москва')
            ->where('savedSearches.0.filters.source.okpd2.0.code', '62.01.11.000')
            ->where('savedSearches.0.filters.source.pages', 1));
});

it('runs a saved EIS search with all stored conditions and records its latest result', function () {
    Queue::fake();
    $fixture = file_get_contents(base_path('tests/Fixtures/eis-rss-initial.xml'));
    expect($fixture)->not->toBeFalse();

    Http::fake([
        'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html*' => Http::response(
            $fixture,
            200,
            ['Content-Type' => 'application/rss+xml'],
        ),
    ]);

    $this->get('/local/mvp-operator')->assertOk();

    $queryId = $this->postJson('/queries', [
        'name' => 'Сайты по 44-ФЗ',
        'keywords' => ['поддержка', 'сайта'],
        'minus_keywords' => ['строительство'],
        'filters' => [
            'relevance' => ['match_mode' => 'any'],
            'source' => [
                'law_44' => true,
                'stage_application' => true,
                'stage_commission' => false,
                'stage_completed' => false,
                'stage_cancelled' => false,
                'joint_purchase' => true,
                'placed_by_separate_subdivision' => true,
                'union_state_budget' => true,
                'created_by_customer_representative' => true,
                'smp_sono' => true,
                'budget_from' => '100000',
                'budget_to' => '750000',
                'published_from' => '2026-08-01',
                'published_to' => '2026-08-27',
                'regions' => [[
                    'code' => '77000000000',
                    'name' => 'г. Москва',
                ]],
                'okpd2' => [[
                    'id' => '8890621',
                    'code' => '62.01.11.000',
                    'name' => 'Разработка программного обеспечения',
                ]],
                'okpd2_with_nested' => true,
                'pages' => 1,
            ],
        ],
    ])->assertCreated()->json('query.id');

    $this->postJson("/queries/{$queryId}/run")
        ->assertOk()
        ->assertJsonPath('query.name', 'Сайты по 44-ФЗ')
        ->assertJsonPath('query.phrase', 'поддержка сайта')
        ->assertJsonPath('query.minus_keywords.0', 'строительство')
        ->assertJsonPath('query.filters.relevance.match_mode', 'any')
        ->assertJsonPath('query.last_run.items_seen', 1)
        ->assertJsonPath('query.last_run.items_matched', 1)
        ->assertJsonPath('query.last_run.pages_loaded', 1)
        ->assertJsonPath('preview.items_matched', 1)
        ->assertJsonPath('tenders.0.match_reason.mode', 'any')
        ->assertJsonPath('tenders.0.match_reason.minus_keywords_checked.0', 'строительство')
        ->assertJsonCount(1, 'tenders');

    $this->assertDatabaseHas('local_mvp_search_snapshots', [
        'search_query_id' => $queryId,
        'query' => 'поддержка сайта',
        'items_matched' => 1,
    ]);

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

        return ($parameters['searchString'] ?? null) === 'поддержка сайта'
            && ($parameters['fz44'] ?? null) === 'on'
            && ($parameters['af'] ?? null) === 'on'
            && ($parameters['jointPurchase'] ?? null) === 'on'
            && ($parameters['isByPlacedSeparateSubdivisions'] ?? null) === 'on'
            && ($parameters['budgetUnionState'] ?? null) === 'on'
            && ($parameters['isByRepresentativeCreated'] ?? null) === 'on'
            && ($parameters['procurementSMPAndSONO'] ?? null) === 'on'
            && ($parameters['priceFromGeneral'] ?? null) === '100000'
            && ($parameters['priceToGeneral'] ?? null) === '750000'
            && ($parameters['publishDateFrom'] ?? null) === '01.08.2026'
            && ($parameters['publishDateTo'] ?? null) === '27.08.2026'
            && ($parameters['delKladrIds'] ?? null) === '77000000000'
            && ($parameters['delKladrIdsCodes'] ?? null) === '77000000000'
            && ($parameters['okpd2Ids'] ?? null) === '8890621'
            && ($parameters['okpd2IdsCodes'] ?? null) === '62.01.11.000'
            && ($parameters['okpd2IdsWithNested'] ?? null) === 'on';
    });
    Queue::assertNotPushed(MatchTender::class);

    $this->get('/local/mvp-operator')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('savedSearches.0.id', $queryId)
            ->where('savedSearches.0.last_run.items_matched', 1)
            ->where('currentSearch.query', 'поддержка сайта')
            ->where('currentSearch.matchMode', 'any')
            ->where('currentSearch.minusKeywords.0', 'строительство')
            ->where('currentTenders.0.match_reason.mode', 'any'));
});

it('does not let another super admin run someone elses saved search', function () {
    $this->get('/local/mvp-operator')->assertOk();
    $owner = User::query()
        ->where('email', 'local-mvp-operator@tenderfinder.invalid')
        ->firstOrFail();
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Закрытый запрос',
        'keywords' => ['поддержка', 'сайта'],
        'status' => 'active',
    ]);
    $otherAdmin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'telegram_id' => 'saved-search-other-admin',
    ]);

    $this->actingAs($otherAdmin)
        ->postJson("/queries/{$query->id}/run")
        ->assertNotFound();

    expect(LocalMvpSearchSnapshot::query()->count())->toBe(0);
});

it('returns saved search run history and only tenders new since the previous run', function () {
    $this->get('/local/mvp-operator')->assertOk();
    $operator = User::query()
        ->where('email', 'local-mvp-operator@tenderfinder.invalid')
        ->firstOrFail();
    $query = SearchQuery::query()->create([
        'user_id' => $operator->id,
        'name' => 'История сайтов',
        'keywords' => ['сайт'],
        'status' => 'active',
    ]);
    $firstTender = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => 'run-history-first',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456771',
        'canonical_url_hash' => hash('sha256', 'run-history-first'),
        'title' => 'Первый тендер',
        'currency' => 'RUB',
    ]);
    $secondTender = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => 'run-history-second',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456772',
        'canonical_url_hash' => hash('sha256', 'run-history-second'),
        'title' => 'Новый тендер второго запуска',
        'currency' => 'RUB',
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'search_query_id' => $query->id,
        'query' => 'сайт',
        'source' => 'eis_rss',
        'tender_ids' => [$firstTender->id],
        'items_seen' => 1,
        'items_matched' => 1,
        'pages_requested' => 1,
        'pages_loaded' => 1,
    ]);
    $latestRun = LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'search_query_id' => $query->id,
        'query' => 'сайт',
        'source' => 'eis_rss',
        'tender_ids' => [$firstTender->id, $secondTender->id],
        'items_seen' => 2,
        'items_matched' => 2,
        'pages_requested' => 1,
        'pages_loaded' => 1,
    ]);

    $this->getJson("/queries/{$query->id}/runs")
        ->assertOk()
        ->assertJsonCount(2, 'runs')
        ->assertJsonPath('runs.0.id', $latestRun->id)
        ->assertJsonPath('runs.0.new_count', 1);

    $this->getJson("/queries/{$query->id}/runs/{$latestRun->id}?only_new=1")
        ->assertOk()
        ->assertJsonPath('only_new', true)
        ->assertJsonPath('run.new_count', 1)
        ->assertJsonPath('tenders.0.id', $secondTender->id)
        ->assertJsonCount(1, 'tenders');

    $otherAdmin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'telegram_id' => 'run-history-other-admin',
    ]);
    $this->actingAs($otherAdmin)
        ->getJson("/queries/{$query->id}/runs")
        ->assertNotFound();
});

it('keeps local result histories and tender statuses scoped to each user', function () {
    $firstUser = User::factory()->create(['telegram_id' => 'local-history-first']);
    $secondUser = User::factory()->create(['telegram_id' => 'local-history-second']);
    $firstTender = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => 'local-history-first',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456781',
        'canonical_url_hash' => hash('sha256', 'local-history-first'),
        'title' => 'Первый тендер',
        'currency' => 'RUB',
    ]);
    $secondTender = Tender::query()->create([
        'source' => 'eis_rss',
        'external_id' => 'local-history-second',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber=01234567890123456782',
        'canonical_url_hash' => hash('sha256', 'local-history-second'),
        'title' => 'Второй тендер',
        'currency' => 'RUB',
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $firstUser->id,
        'query' => 'Первый запрос',
        'source' => 'eis_rss',
        'tender_ids' => [$firstTender->id],
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $secondUser->id,
        'query' => 'Второй запрос',
        'source' => 'eis_rss',
        'tender_ids' => [$secondTender->id],
    ]);

    $snapshots = app(LocalMvpSearchSnapshotService::class);
    $workspace = app(LocalMvpTenderWorkspaceService::class);
    $firstHistory = $workspace->historyTendersFor(
        $firstUser,
        $snapshots->historyTenderIdsFor($firstUser),
    );
    $secondHistory = $workspace->historyTendersFor(
        $secondUser,
        $snapshots->historyTenderIdsFor($secondUser),
    );

    expect(collect($firstHistory)->pluck('id')->all())->toBe([$firstTender->id])
        ->and(collect($secondHistory)->pluck('id')->all())->toBe([$secondTender->id]);

    $workspace->updateStatus($firstUser, $firstTender, TenderUserStatus::Potential);

    expect($workspace->tendersForIds($firstUser, [$firstTender->id])[0]['status'])
        ->toBe('potential')
        ->and($workspace->tendersForIds($secondUser, [$firstTender->id]))
        ->toBe([]);
});

it('rejects an inverted EIS budget range before making a request', function () {
    Http::fake();

    $this->get('/local/mvp-operator')->assertOk();

    $this->postJson('/local/mvp/eis-rss-preview', [
        'query' => 'поддержка сайта',
        'budget_from' => '500000',
        'budget_to' => '100000',
    ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.budget_to.0',
            'Верхняя граница НМЦК не может быть меньше нижней.',
        );

    Http::assertNothingSent();
});

it('reports a safe certificate error when a manual EIS RSS import cannot verify TLS', function () {
    Http::fake([
        'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html*' => Http::failedConnection(
            'cURL error 60: SSL certificate problem',
        ),
    ]);

    $this->get('/local/mvp-operator')->assertOk();

    $this->postJson('/local/mvp/eis-rss-preview', [
        'query' => 'разработка сайта',
    ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.query.0',
            'Сервер не смог безопасно проверить сертификат ЕИС. Попробуйте позже.',
        );
});

it('stores favorite, potential and hidden states only for the local operator', function () {
    $this->get('/local/mvp-operator')->assertOk();
    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();
    $tender = Tender::query()->create([
        'source' => 'tenderguru_preview',
        'external_id' => 'local-workspace-state',
        'canonical_url' => 'https://example.test/tender/local-workspace-state',
        'canonical_url_hash' => hash('sha256', 'local-workspace-state'),
        'title' => 'Тендер для проверки состояний',
        'currency' => 'RUB',
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'query' => 'Тендер для проверки состояний',
        'source' => 'tenderguru_preview',
        'tender_ids' => [$tender->id],
    ]);

    $this->postJson("/local/mvp/tenders/{$tender->id}/status", ['status' => 'favorite'])
        ->assertOk()
        ->assertJsonPath('tender.status', 'favorite');

    $this->postJson("/local/mvp/tenders/{$tender->id}/status", ['status' => 'potential'])
        ->assertOk()
        ->assertJsonPath('tender.status', 'potential');

    $this->postJson("/local/mvp/tenders/{$tender->id}/status", ['status' => 'dismissed'])
        ->assertOk()
        ->assertJsonPath('tender.status', 'dismissed');

    $this->postJson("/local/mvp/tenders/{$tender->id}/status", ['status' => 'archived'])
        ->assertOk()
        ->assertJsonPath('tender.status', 'archived');

    expect(TenderUserState::query()
        ->where('user_id', $operator->id)
        ->where('tender_id', $tender->id)
        ->value('status'))->toBe(TenderUserStatus::Archived);
});

it('renders a local tender detail with only source-provided data and attachments', function () {
    $this->get('/local/mvp-operator')->assertOk();
    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();
    $tender = Tender::query()->create([
        'source' => 'tenderguru_preview',
        'external_id' => 'local-workspace-detail',
        'canonical_url' => 'https://example.test/tender/local-workspace-detail',
        'canonical_url_hash' => hash('sha256', 'local-workspace-detail'),
        'title' => 'Разработка сайта учреждения',
        'description' => 'Описание, переданное источником.',
        'region' => 'Москва',
        'budget_amount' => '125000.00',
        'currency' => 'RUB',
        'metadata' => [
            'customer' => 'Учреждение-заказчик',
            'category' => 'IT',
            'procurement_law' => '44',
            'attachments' => [
                [
                    'label' => 'Техническое задание.pdf',
                    'url' => 'https://example.test/files/specification.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 2048,
                ],
            ],
        ],
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'query' => 'Разработка сайта учреждения',
        'source' => 'tenderguru_preview',
        'tender_ids' => [$tender->id],
    ]);

    $this->get("/local/mvp/tenders/{$tender->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MvpTenderDetail')
            ->where('tender.id', $tender->id)
            ->where('tender.customer', 'Учреждение-заказчик')
            ->where('tender.attachments.0.label', 'Техническое задание.pdf')
            ->where('tender.attachments.0.url', 'https://example.test/files/specification.pdf'));
});

it('updates only the local operators selected tender states in bulk without deleting tenders', function () {
    $this->get('/local/mvp-operator')->assertOk();
    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();
    $otherUser = User::factory()->create(['telegram_id' => '777001']);
    $tenders = collect(['bulk-one', 'bulk-two'])->map(fn (string $externalId): Tender => Tender::query()->create([
        'source' => 'tenderguru_preview',
        'external_id' => $externalId,
        'canonical_url' => "https://example.test/tender/{$externalId}",
        'canonical_url_hash' => hash('sha256', $externalId),
        'title' => "Тендер {$externalId}",
        'currency' => 'RUB',
    ]));
    TenderUserState::query()->create([
        'user_id' => $otherUser->id,
        'tender_id' => $tenders->first()->id,
        'status' => TenderUserStatus::Favorite,
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $operator->id,
        'query' => 'Групповая проверка',
        'source' => 'tenderguru_preview',
        'tender_ids' => $tenders->pluck('id')->all(),
    ]);

    $this->postJson('/local/mvp/tenders/status', [
        'tender_ids' => $tenders->pluck('id')->all(),
        'status' => 'archived',
    ])
        ->assertOk()
        ->assertJsonCount(2, 'tenders');

    foreach ($tenders as $tender) {
        $this->assertDatabaseHas('tender_user_states', [
            'user_id' => $operator->id,
            'tender_id' => $tender->id,
            'status' => TenderUserStatus::Archived->value,
        ]);
        expect(Tender::query()->whereKey($tender->id)->exists())->toBeTrue();
    }

    $this->assertDatabaseHas('tender_user_states', [
        'user_id' => $otherUser->id,
        'tender_id' => $tenders->first()->id,
        'status' => TenderUserStatus::Favorite->value,
    ]);

    $this->postJson('/local/mvp/tenders/status', [
        'tender_ids' => [$tenders->first()->id],
        'status' => 'new',
    ])->assertOk();

    $this->assertDatabaseMissing('tender_user_states', [
        'user_id' => $operator->id,
        'tender_id' => $tenders->first()->id,
    ]);
});
