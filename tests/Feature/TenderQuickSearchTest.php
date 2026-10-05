<?php

use App\Jobs\DeliverTelegramNotification;
use App\Jobs\MatchTender;
use App\Jobs\PollB2bCenterFeed;
use App\Jobs\PollRostenderTemplate;
use App\Jobs\PollWorkspaceRuFeed;
use App\Models\Entitlement;
use App\Models\RostenderApiUsage;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedItem;
use App\Models\SourceFeedSearchQuery;
use App\Models\SourceRun;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\B2bCenterHtmlParser;
use App\Services\B2bCenterSource;
use App\Services\CachedMonitoringMatchService;
use App\Services\PlanCatalog;
use App\Services\PublicTenderSources;
use App\Services\TenderKeywordMatcher;
use App\Services\TenderMatchingService;
use App\Services\TenderSourceImportService;
use App\Services\WorkspaceRuSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set([
        'tender.rostender.enabled' => true,
        'tender.rostender.public_distribution_approved' => true,
        'tender.rostender.basic_active_monitor_limit' => 3,
        'tender.rostender.basic_poll_interval_seconds' => 3600,
    ]);
});

it('requires active access and an approved source before creating a quick monitoring', function () {
    discoveryFeed();
    $guest = User::factory()->create();
    $this->actingAs($guest)->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertUnprocessable();
    config()->set('tender.rostender.public_distribution_approved', false);
    $this->actingAs(discoveryUser())->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertUnprocessable();
    expect(SearchQuery::query()->count())->toBe(0);
});

it('starts an individual monitoring, shows current matches and reports a delayed source without API calls', function () {
    $user = discoveryUser();
    $feed = discoveryFeed();
    $matching = discoveryTender('Разработка сайта для заказчика', 'match-1', $feed);
    discoveryTender('Поставка мебели', 'other-1', $feed);
    discoveryTender('Разработка сайта с истёкшим сроком', 'expired-1', $feed)
        ->forceFill(['deadline_at' => now()->subDay()])->save();
    $feed->forceFill([
        'last_success_at' => now()->subDays(14),
        'last_error_code' => 'quota_exhausted',
        'next_poll_at' => now()->addDay(),
    ])->save();
    SourceRun::query()->create([
        'source_feed_id' => $feed->id,
        'source' => 'rostender',
        'status' => 'failed',
        'started_at' => now(),
        'finished_at' => now(),
        'error_code' => 'quota_exhausted',
    ]);
    Http::fake();

    RostenderApiUsage::query()->create([
        'usage_date' => now('Europe/Moscow')->toDateString(),
        'successful_requests' => 200,
        'in_flight_requests' => 0,
    ]);

    $this->actingAs($user)->get('/queries')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rostenderTemplates.0.id', 42));

    $queryId = $this->actingAs($user)->postJson('/queries/quick', [
        'phrase' => 'Разработка сайта',
    ])->assertCreated()->assertJsonPath('cached_matches', 1)->json('query.id');

    expect(SearchQuery::query()->findOrFail($queryId)->status->value)->toBe('active')
        ->and(SearchQuery::query()->findOrFail($queryId)->filters['relevance']['match_mode'])->toBe('phrase')
        ->and(TenderQueryMatch::query()->where('search_query_id', $queryId)->where('tender_id', $matching->id)->exists())->toBeTrue();
    Http::assertNothingSent();

    $this->actingAs($user)->get('/tenders?query_id='.$queryId.'&started=1')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenderMatches.total', 1)
            ->where('searchStarted', true)
            ->where('monitoringStatus.state', 'error'));

    $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertOk()->assertJsonPath('reused', true);
    expect(SearchQuery::query()->count())->toBe(1);
});

it('rejects ambiguous templates instead of silently searching only one of them', function () {
    $user = discoveryUser();
    discoveryFeed();
    SourceFeed::query()->create([
        'source' => 'rostender',
        'source_identifier' => 43,
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/43',
        'url_hash' => hash('sha256', 'rostender:template:43'),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
    ]);
    $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertUnprocessable();
    expect(SearchQuery::query()->count())->toBe(0);
});

it('queues a due source check when the first search is started', function () {
    Queue::fake();
    $user = discoveryUser();
    $feed = discoveryFeed();

    $this->actingAs($user)->postJson('/queries/quick', [
        'phrase' => 'Разработка сайта',
    ])->assertCreated()->assertJsonPath('check_queued', true);

    Queue::assertPushed(PollRostenderTemplate::class, fn (PollRostenderTemplate $job): bool => $job->feedId === $feed->id);
});

function discoveryUser(): User
{
    $user = User::factory()->create();
    $plan = app(PlanCatalog::class)->basic();
    Entitlement::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => 'active',
        'value' => 3,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
    ]);

    return $user;
}

function discoveryFeed(): SourceFeed
{
    return SourceFeed::query()->firstOrCreate([
        'source' => 'rostender', 'source_identifier' => 42,
    ], [
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/42',
        'url_hash' => hash('sha256', 'rostender:template:42'),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
        'next_poll_at' => now(),
    ]);
}

function discoveryTender(string $title, string $externalId, ?SourceFeed $feed = null): Tender
{
    $feed ??= discoveryFeed();
    $item = SourceFeedItem::query()->create([
        'source_feed_id' => $feed->id,
        'external_id' => $externalId,
        'canonical_url' => 'https://rostender.info/tender/'.$externalId,
        'url_hash' => hash('sha256', $externalId),
        'content_hash' => hash('sha256', $title),
        'discovered_at' => now(),
        'title' => $title,
    ]);

    return Tender::query()->create([
        'source' => 'rostender',
        'external_id' => $externalId,
        'source_feed_item_id' => $item->id,
        'canonical_url' => $item->canonical_url,
        'canonical_url_hash' => $item->url_hash,
        'title' => $title,
        'currency' => 'RUB',
    ]);
}

it('searches a public feed without RosTender and keeps personal matches isolated and idempotent', function () {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
    config()->set('tender.rostender.enabled', false);
    config()->set('tender.workspace_ru.enabled', true);
    Http::fake(['https://workspace.ru/tenders/rss/' => Http::response(workspaceRuRss())]);
    Queue::fake();
    $user = discoveryUser();
    $other = discoveryUser();

    $queryId = $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'Разработка'])
        ->assertCreated()->assertJsonPath('check_queued', true)->json('query.id');
    $feed = SourceFeed::query()->where('source', 'workspace_ru')->sole();
    Queue::assertPushed(PollWorkspaceRuFeed::class, 1);
    (new PollWorkspaceRuFeed($feed->id))->handle(
        app(WorkspaceRuSource::class), app(TenderSourceImportService::class),
    );
    $matcher = app(TenderMatchingService::class);
    foreach (Tender::query()->where('source', 'workspace_ru')->get() as $tender) {
        $matcher->matchTender($tender, false);
    }
    expect(TenderQueryMatch::query()->where('search_query_id', $queryId)->count())->toBe(1);
    $this->actingAs($other)->get('/tenders')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 0));
    $this->actingAs($other)->postJson('/queries/'.$queryId.'/run')->assertNotFound();
    $this->actingAs($user)->get('/tenders?query_id='.$queryId)->assertInertia(fn (Assert $page) => $page
        ->where('tenderMatches.total', 1)->where('monitoringStatuses.0.source', 'workspace_ru')
        ->where('monitoringStatuses.0.state', 'ok'));
    $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'Разработка'])
        ->assertOk()->assertJsonPath('reused', true)->assertJsonPath('check_queued', false);
    expect(SearchQuery::query()->count())->toBe(1)
        ->and(TenderQueryMatch::query()->count())->toBe(1);
    Queue::assertPushed(PollWorkspaceRuFeed::class, 1);
    Queue::assertNotPushed(DeliverTelegramNotification::class);
});

it('fills initial public results for a new monitoring and excludes expired or disabled feeds', function () {
    config()->set('tender.rostender.enabled', false);
    config()->set('tender.workspace_ru.enabled', true);
    Queue::fake();
    app(PublicTenderSources::class)->synchronize();
    $feed = SourceFeed::query()->where('source', 'workspace_ru')->sole();
    $matching = discoveryTender('Разработка портала', 'public-open', $feed);
    $matching->forceFill(['source' => 'workspace_ru'])->save();
    $expired = discoveryTender('Разработка архивная', 'public-expired', $feed);
    $expired->forceFill(['source' => 'workspace_ru', 'deadline_at' => now()->subDay()])->save();
    $disabled = discoveryTender('Разработка из выключенного источника', 'disabled', $feed);
    $disabled->forceFill(['source' => 'b2b_center'])->save();

    $this->actingAs(discoveryUser())->postJson('/queries/quick', ['phrase' => 'Разработка'])
        ->assertCreated()->assertJsonPath('cached_matches', 1);
    expect(TenderQueryMatch::query()->sole()->tender_id)->toBe($matching->id);
});

it('continues public checks when RosTender quota is exhausted and reports both source states', function () {
    Queue::fake();
    config()->set('tender.workspace_ru.enabled', true);
    $feed = discoveryFeed();
    RostenderApiUsage::query()->create([
        'usage_date' => now('Europe/Moscow')->toDateString(), 'successful_requests' => 200, 'in_flight_requests' => 0,
    ]);
    SourceRun::query()->create([
        'source_feed_id' => $feed->id, 'source' => 'rostender', 'status' => 'failed',
        'started_at' => now(), 'finished_at' => now(), 'error_code' => 'quota_exhausted',
    ]);
    $queryId = $this->actingAs(discoveryUser())->postJson('/queries/quick', ['phrase' => 'Разработка'])
        ->assertCreated()->assertJsonPath('check_queued', true)->json('query.id');
    Queue::assertPushed(PollWorkspaceRuFeed::class, 1);
    Queue::assertNotPushed(PollRostenderTemplate::class);
    $this->get('/tenders?query_id='.$queryId)->assertInertia(fn (Assert $page) => $page
        ->has('monitoringStatuses', 2)->where('monitoringStatuses.0.state', 'error')
        ->where('monitoringStatuses.1.state', 'queued'));
    $this->postJson('/queries/'.$queryId.'/run')->assertOk()->assertJsonPath('queued', false)->assertJsonStructure(['message']);
    Queue::assertPushed(PollWorkspaceRuFeed::class, 1);
});

it('can preview and edit a public-only monitoring without a RosTender template', function () {
    config()->set('tender.rostender.enabled', false);
    config()->set('tender.workspace_ru.enabled', true);
    Queue::fake();
    $queryId = $this->actingAs(discoveryUser())->postJson('/queries/quick', ['phrase' => 'Разработка'])
        ->assertCreated()->json('query.id');
    $payload = ['keywords' => ['портал'], 'filters' => ['source' => ['rostender_template_id' => null]]];
    $this->patchJson('/queries/'.$queryId, $payload)->assertOk();
    $this->postJson('/queries/preview', $payload)->assertOk()->assertJsonPath('checked', 0);
    $this->get('/queries')->assertInertia(fn (Assert $page) => $page->where('publicSources.0', 'workspace_ru'));
});

it('requests B2B by the user keywords and shares the source check without sharing personal state', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    config()->set('tender.rostender.enabled', false);
    config()->set('tender.b2b_center.enabled', true);
    Queue::fake();
    Http::fake(fn () => Http::response(b2bCenterApiFixture()));
    $user = discoveryUser();
    $other = discoveryUser();
    $first = $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'разработка сайта'])
        ->assertCreated()->assertJsonPath('check_queued', true)->json('query.id');
    $second = $this->actingAs($other)->postJson('/queries/quick', ['phrase' => 'разработка сайта'])
        ->assertCreated()->assertJsonPath('check_queued', false)->json('query.id');
    $feed = SourceFeedSearchQuery::query()->where('search_query_id', $first)->sole()->feed;
    expect($feed->canonical_url)->toBe('https://www.b2b-center.ru/market/?'.http_build_query(['f_keyword' => 'разработка сайта', 'searching' => 1]));
    Queue::assertPushed(PollB2bCenterFeed::class, 1);
    (new PollB2bCenterFeed($feed->id))->handle(app(B2bCenterSource::class), app(TenderSourceImportService::class));
    foreach (Tender::query()->get() as $tender) {
        (new MatchTender($tender->id, false))->handle(app(TenderMatchingService::class));
    }
    expect(TenderQueryMatch::query()->where('search_query_id', $first)->count())->toBe(1)
        ->and(TenderQueryMatch::query()->where('search_query_id', $second)->count())->toBe(1);
    Http::assertSent(fn ($request) => $request['query'] === 'разработка сайта' && ! $request->hasHeader('Cookie') && ! $request->hasHeader('Authorization'));
    $this->actingAs($user)->postJson('/queries/'.$first.'/pause')->assertOk();
    expect($feed->fresh()->status)->toBe('active');
    $this->actingAs($other)->postJson('/queries/'.$second.'/pause')->assertOk();
    expect($feed->fresh()->status)->toBe('paused');
    $this->get('/queries')->assertInertia(fn (Assert $page) => $page
        ->where('queries.0.source_statuses.0.state', 'paused')
        ->where('queries.0.source_statuses.0.last_success_items_seen', 2));
    $this->postJson('/queries/'.$second.'/resume')->assertOk();
    expect($feed->fresh()->status)->toBe('active');
});

it('handles common Russian endings while preserving exact phrase matching', function () {
    $query = new SearchQuery(['keywords' => ['разработка', 'сайта']]);
    $tender = new Tender(['title' => 'Услуги разработки сайта по техническому заданию']);
    $matcher = app(TenderMatchingService::class);
    expect($matcher->evaluate($query, $tender)->matches)->toBeTrue();
    $query->filters = ['relevance' => ['match_mode' => 'exact']];
    expect($matcher->evaluate($query, $tender)->matches)->toBeFalse();
    expect(app(TenderKeywordMatcher::class)->contains('Разработка приложения', 'сайт'))->toBeFalse();
});

it('does not treat a Workspace category or an unrelated project as a phrase match', function () {
    $query = new SearchQuery([
        'keywords' => ['разработка', 'сайта'],
        'filters' => ['relevance' => ['match_mode' => 'phrase']],
    ]);
    $matcher = app(TenderMatchingService::class);

    $unrelated = new Tender([
        'source' => 'workspace_ru',
        'title' => 'Система приёма донатов в USDT',
        'description' => "Требуемая услуга: разработка сайтов под ключ\n\nНужна интеграция криптоплатежей.",
    ]);
    $otherService = new Tender([
        'source' => 'workspace_ru',
        'title' => 'PR-сопровождение запуска продукта',
        'description' => "Требуемая услуга: PR\n\nРазработка сайта уже поручена другой команде.",
    ]);
    $relevant = new Tender([
        'source' => 'workspace_ru',
        'title' => 'Новый сайт для отеля',
        'description' => "Требуемая услуга: разработка сайтов\n\nНужна разработка нового сайта с бронированием.",
    ]);

    expect($matcher->evaluate($query, $unrelated)->matches)->toBeFalse()
        ->and($matcher->evaluate($query, $otherService)->matches)->toBeFalse()
        ->and($matcher->evaluate($query, $relevant)->matches)->toBeTrue();
});

it('includes a website creation tender in a website development search without including widgets', function () {
    $query = new SearchQuery([
        'keywords' => ['разработка', 'сайта'],
        'filters' => ['relevance' => ['match_mode' => 'phrase']],
    ]);
    $matcher = app(TenderMatchingService::class);

    $website = new Tender(['source' => 'workspace_ru', 'title' => 'Создание и SEO-продвижение сайта-зеркала для магазина дверей']);
    $widget = new Tender(['source' => 'workspace_ru', 'title' => 'Создание виджета для сайта IT-агентства']);

    expect($matcher->evaluate($query, $website)->matches)->toBeTrue()
        ->and($matcher->evaluate($query, $widget)->matches)->toBeFalse();
});

it('upgrades a reused quick search and removes derived matches outside its phrase', function () {
    config()->set('tender.rostender.enabled', false);
    config()->set('tender.workspace_ru.enabled', true);
    Queue::fake();
    $user = discoveryUser();
    $query = SearchQuery::query()->create([
        'user_id' => $user->id, 'name' => 'разработка сайта',
        'keywords' => ['разработка', 'сайта'], 'status' => 'active',
        'filters' => ['source' => ['rostender_template_id' => null]],
    ]);
    $unrelated = Tender::query()->create([
        'source' => 'workspace_ru', 'external_id' => 'wrong-category',
        'canonical_url' => 'https://workspace.ru/tenders/wrong-category/',
        'canonical_url_hash' => hash('sha256', 'wrong-category'),
        'title' => 'Система приёма донатов',
        'description' => "Требуемая услуга: разработка сайтов\n\nНужны криптоплатежи.",
        'currency' => 'RUB',
    ]);
    TenderQueryMatch::query()->create([
        'search_query_id' => $query->id, 'tender_id' => $unrelated->id,
        'match_reasons' => ['keywords' => $query->keywords], 'matched_at' => now(),
    ]);

    $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'разработка сайта'])
        ->assertOk()->assertJsonPath('reused', true);
    expect($query->fresh()->filters['relevance']['match_mode'])->toBe('phrase')
        ->and(TenderQueryMatch::query()->where('search_query_id', $query->id)->count())->toBe(0)
        ->and(Tender::query()->whereKey($unrelated->id)->exists())->toBeTrue();
});

it('does not distribute a B2B search result to a different keyword feed', function () {
    $first = SearchQuery::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Первый', 'keywords' => ['сайт'], 'status' => 'active',
    ]);
    $second = SearchQuery::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Второй', 'keywords' => ['сайт'], 'status' => 'active',
    ]);
    $feed = SourceFeed::query()->create([
        'source' => 'b2b_center', 'canonical_url' => 'https://www.b2b-center.ru/market/?f_keyword=сайт&searching=1',
        'url_hash' => hash('sha256', 'first-b2b'), 'status' => 'active', 'poll_interval_seconds' => 3600,
    ]);
    SourceFeedSearchQuery::query()->create(['source_feed_id' => $feed->id, 'search_query_id' => $first->id]);
    SourceFeedItem::query()->create([
        'source_feed_id' => $feed->id, 'external_id' => 'first-only',
        'canonical_url' => 'https://www.b2b-center.ru/market/tender-first-only/',
        'url_hash' => hash('sha256', 'first-only'), 'title' => 'Разработка сайта',
        'content_hash' => hash('sha256', 'first-only-content'), 'discovered_at' => now(),
    ]);
    $tender = Tender::query()->create([
        'source' => 'b2b_center', 'external_id' => 'first-only',
        'canonical_url' => 'https://www.b2b-center.ru/market/tender-first-only/',
        'canonical_url_hash' => hash('sha256', 'first-only'),
        'title' => 'Разработка сайта', 'currency' => 'RUB',
    ]);

    app(TenderMatchingService::class)->matchTender($tender, false);
    expect(TenderQueryMatch::query()->where('search_query_id', $first->id)->count())->toBe(1)
        ->and(TenderQueryMatch::query()->where('search_query_id', $second->id)->count())->toBe(0);
});

it('finds a cached public card even after another shared feed updates its canonical pointer', function () {
    config()->set('tender.rostender.enabled', false);
    config()->set('tender.b2b_center.enabled', true);
    Queue::fake();
    $id = $this->actingAs(discoveryUser())->postJson('/queries/quick', ['phrase' => 'разработка сайта'])->assertCreated()->json('query.id');
    $query = SearchQuery::query()->findOrFail($id);
    $feed = SourceFeedSearchQuery::query()->where('search_query_id', $id)->sole()->feed;
    $html = str_replace('Закупка цинкооксидных поглотителей для загрузки системы', 'Разработка сайта', b2bCenterHtml());
    $result = app(B2bCenterHtmlParser::class)->parse($html, 'https://www.b2b-center.ru/market/');
    $importer = app(TenderSourceImportService::class);
    $importer->import($feed, $result, 'b2b_center', false);
    $global = SourceFeed::query()->where('canonical_url', 'https://www.b2b-center.ru/market/')->sole();
    $importer->import($global, $result, 'b2b_center', false);
    expect(app(CachedMonitoringMatchService::class)->fill($query))->toBe(1)
        ->and(app(CachedMonitoringMatchService::class)->fill($query))->toBe(0);
    Queue::assertNotPushed(DeliverTelegramNotification::class);
});
