<?php

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Jobs\PollEtpGpbFeed;
use App\Jobs\PollWorkspaceRuFeed;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedItem;
use App\Models\SourceFeedSearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\AccessService;
use App\Services\PlanCatalog;
use App\Services\PublicTenderSources;
use App\Services\TelegramBotClient;
use App\Services\TenderMatchingService;
use App\Services\TenderSummary;
use App\Tenders\RostenderSearchTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Fixtures/team-workspace.php';

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set([
        'tender.rostender.enabled' => false,
        'tender.workspace_ru.enabled' => true,
        'tender.etp_gpb.enabled' => true,
        'tender.b2b_center.enabled' => false,
    ]);
    app(PublicTenderSources::class)->synchronize();
});

it('imports cached matches and dispatches checks only for the selected monitoring platforms', function () {
    $user = platformChoiceUser();
    platformChoiceTender('workspace_ru', 'chosen');
    platformChoiceTender('etp_gpb', 'excluded');
    $this->actingAs($user)->postJson('/queries', platformChoicePayload(['workspace_ru']))
        ->assertCreated()->assertJsonPath('cached_matches', 1)
        ->assertJsonPath('query.filters.source.platforms', ['workspace_ru'])
        ->assertJsonCount(1, 'query.source_statuses')
        ->assertJsonPath('query.source_statuses.0.source', 'workspace_ru');

    expect(TenderQueryMatch::query()->with('tender')->sole()->tender->source)->toBe('workspace_ru');
    Queue::assertPushed(PollWorkspaceRuFeed::class);
    Queue::assertNotPushed(PollEtpGpbFeed::class);
    Http::assertNothingSent();
});

it('updates platform choices without losing saved history and blocks deselected pending notifications', function () {
    $user = platformChoiceUser();
    $old = platformChoiceTender('workspace_ru', 'old');
    $next = platformChoiceTender('etp_gpb', 'next');
    $queryId = $this->actingAs($user)->postJson('/queries', platformChoicePayload(['workspace_ru']))->assertCreated()->json('query.id');
    $delivery = NotificationDelivery::query()->create([
        'user_id' => $user->id, 'search_query_id' => $queryId, 'tender_id' => $old->id,
        'type' => 'tender_card', 'status' => NotificationStatus::Queued,
        'idempotency_key' => 'platform-switch-test', 'payload' => ['title' => $old->title], 'scheduled_at' => now(),
    ]);
    $this->patchJson('/queries/'.$queryId, platformChoicePayload(['etp_gpb']))
        ->assertOk()->assertJsonPath('query.filters.source.platforms', ['etp_gpb'])
        ->assertJsonPath('query.source_statuses.0.source', 'etp_gpb');

    $query = SearchQuery::query()->findOrFail($queryId);
    expect(TenderQueryMatch::query()->where('search_query_id', $queryId)->pluck('tender_id')->all())
        ->toContain($old->id, $next->id);
    $newOldSource = platformChoiceTender('workspace_ru', 'later');
    expect(app(TenderMatchingService::class)->matchTenderForQueries($newOldSource, [$query]))->toBe(0);
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldNotReceive('sendNotification');
    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));
    expect($delivery->refresh()->status)->toBe(NotificationStatus::Skipped)
        ->and($delivery->failure_code)->toBe('source_deselected');

    $other = platformChoiceUser();
    $this->actingAs($other)->patchJson('/queries/'.$queryId, platformChoicePayload(['workspace_ru']))->assertNotFound();
});

it('keeps all connected platforms for legacy queries and rejects empty unknown or duplicate selections', function () {
    $user = platformChoiceUser();
    platformChoiceTender('workspace_ru', 'legacy-one');
    platformChoiceTender('etp_gpb', 'legacy-two');
    $this->actingAs($user)->postJson('/queries', platformChoicePayload(null))
        ->assertCreated()->assertJsonPath('cached_matches', 2);

    foreach ([[], ['invented'], ['workspace_ru', 'workspace_ru']] as $platforms) {
        $this->postJson('/queries', platformChoicePayload($platforms))->assertUnprocessable();
    }
    config()->set('tender.workspace_ru.enabled', false);
    $this->postJson('/queries', platformChoicePayload(['workspace_ru']))->assertUnprocessable();
});

it('keeps selected platforms in preview and quick searches and does not reuse a different selection', function () {
    $user = platformChoiceUser();
    platformChoiceTender('workspace_ru', 'preview-one');
    platformChoiceTender('etp_gpb', 'preview-two');
    $this->actingAs($user)->postJson('/queries/preview', platformChoicePayload(['etp_gpb']))
        ->assertOk()->assertJsonPath('checked', 1)->assertJsonPath('matched', 1);
    $first = $this->postJson('/queries/quick', ['phrase' => 'Разработка сайта', 'platforms' => ['workspace_ru']])
        ->assertCreated()->assertJsonPath('cached_matches', 1)->json('query.id');
    $this->postJson('/queries/quick', ['phrase' => 'Разработка сайта', 'platforms' => ['workspace_ru']])
        ->assertOk()->assertJsonPath('reused', true)->assertJsonPath('query.id', $first);
    $second = $this->postJson('/queries/quick', ['phrase' => 'Разработка сайта', 'platforms' => ['etp_gpb']])
        ->assertCreated()->assertJsonPath('cached_matches', 1)->json('query.id');
    expect($second)->not->toBe($first);
});

it('detaches unselected B2B keyword feeds without affecting another users monitoring', function () {
    config()->set('tender.b2b_center.enabled', true);
    app(PublicTenderSources::class)->synchronize();
    $first = platformChoiceUser();
    $second = platformChoiceUser();
    $queryId = $this->actingAs($first)->postJson('/queries', platformChoicePayload(['b2b_center']))->assertCreated()->json('query.id');
    $otherId = $this->actingAs($second)->postJson('/queries', platformChoicePayload(['b2b_center']))->assertCreated()->json('query.id');
    $feedId = SourceFeedSearchQuery::query()->where('search_query_id', $queryId)->sole()->source_feed_id;
    $this->actingAs($first)->patchJson('/queries/'.$queryId, platformChoicePayload(['workspace_ru']))->assertOk();
    expect(SourceFeedSearchQuery::query()->where('search_query_id', $queryId)->exists())->toBeFalse()
        ->and(SourceFeedSearchQuery::query()->where('search_query_id', $otherId)->exists())->toBeTrue()
        ->and(SourceFeed::query()->findOrFail($feedId)->status)->toBe('active');
});

it('filters several platforms before pagination and preserves them in saved personal views', function () {
    $user = platformChoiceUser();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Сайты', 'keywords' => ['сайт'], 'status' => 'active']);
    foreach (range(1, 14) as $index) {
        $tender = platformChoiceTender($index === 14 ? 'etp_gpb' : 'workspace_ru', 'page-'.$index);
        TenderQueryMatch::query()->create(['tender_id' => $tender->id, 'search_query_id' => $query->id, 'match_reasons' => [], 'matched_at' => now()]);
    }
    $params = http_build_query(['sources' => ['workspace_ru', 'etp_gpb']]);
    $this->actingAs($user)->get('/tenders?'.$params)->assertInertia(fn (Assert $page) => $page
        ->where('tenderMatches.total', 14)->where('tenderMatches.last_page', 2)
        ->has('filters.sources', 2)->has('tenderMatches.data.0.summary'));
    $this->get('/tenders?sources[]=etp_gpb')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 1));
    $this->get('/tenders?sources[chosen]=etp_gpb')->assertInertia(fn (Assert $page) => $page
        ->where('tenderMatches.total', 1)->where('filters.sources', ['etp_gpb']));
    $this->postJson('/tender-feed-views', ['name' => 'Две площадки', 'filters' => ['sources' => ['workspace_ru', 'etp_gpb'], 'query_id' => 0]])
        ->assertCreated()->assertJsonPath('view.filters.sources', ['workspace_ru', 'etp_gpb']);
    $this->get('/tenders?sources[]=unknown')->assertSessionHasErrors('sources.0');
    $this->actingAs(platformChoiceUser())->get('/tenders?'.$params)->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 0));
});

it('filters team feeds by multiple sources and leaves unshared tenders private', function () {
    [$owner, $member, , $team, $tender] = teamFixture();
    $tender->forceFill(['source' => 'workspace_ru'])->save();
    $query = SearchQuery::query()->where('user_id', $owner->id)->sole();
    DB::table('team_search_queries')->insert([
        'team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs($member)->get('/tenders?team_id='.$team->id.'&sources[]=workspace_ru&sources[]=etp_gpb')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 1)->has('filters.sources', 2));
    $this->get('/tenders?team_id='.$team->id.'&sources[]=etp_gpb')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 0));
});

it('returns factual summaries on accessible detail and work pages without exposing metadata', function () {
    $user = platformChoiceUser();
    $tender = platformChoiceTender('workspace_ru', 'summary');
    $tender->update([
        'budget_amount' => '1500000.25',
        'metadata' => ['application_security' => '15 000 ₽', 'contract_security' => ['private' => 'do not publish'], 'delivery_place' => 'Москва'],
    ]);
    $queryId = $this->actingAs($user)->postJson('/queries', platformChoicePayload(['workspace_ru']))->assertCreated()->json('query.id');
    $this->get('/tenders/'.$tender->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tender.summary.budget_amount', '1500000.25')
        ->where('tender.summary.application_security', '15 000 ₽')
        ->where('tender.summary.contract_security', null)
        ->where('tender.summary.delivery_place', 'Москва')
        ->where('tender.summary.source_label', 'Workspace.ru'));
    $this->get('/tenders/'.$tender->id.'/work')->assertOk()->assertInertia(fn (Assert $page) => $page->has('tender.summary'));
    $tender->update(['budget_amount' => null, 'published_at' => null, 'deadline_at' => null]);
    $summary = TenderSummary::forTender($tender->refresh());
    expect($summary['budget_amount'])->toBeNull()->and($summary['published_at'])->toBeNull()->and($summary['deadline_at'])->toBeNull();
    $this->actingAs(platformChoiceUser())->get('/tenders/'.$tender->id)->assertNotFound();
});

it('uses only RosTender when explicitly selected and detaches its template when deselected', function () {
    config()->set([
        'tender.rostender.enabled' => true,
        'tender.rostender.public_distribution_approved' => true,
        'tender.rostender.basic_active_monitor_limit' => 3,
        'tender.rostender.basic_poll_interval_seconds' => 3600,
    ]);
    Cache::put('rostender:search-templates:v1', [new RostenderSearchTemplate(42, 'Тестовый шаблон')], now()->addMinutes(30));
    $user = platformChoiceUser();
    $queryId = $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'Разработка сайта', 'platforms' => ['rostender']])
        ->assertCreated()->assertJsonPath('query.filters.source.rostender_template_id', 42)
        ->assertJsonPath('query.source_statuses.0.source', 'rostender')->json('query.id');
    expect(RostenderFeedSearchQuery::query()->where('search_query_id', $queryId)->exists())->toBeTrue();
    $this->patchJson('/queries/'.$queryId, platformChoicePayload(['workspace_ru']))->assertOk();
    expect(RostenderFeedSearchQuery::query()->where('search_query_id', $queryId)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('leaves deselected historical matches out of newly generated daily digests', function () {
    $this->travelTo(now()->setTimezone('Europe/Moscow')->setTime(9, 0));
    $user = platformChoiceUser();
    platformChoiceTender('workspace_ru', 'digest-selected');
    platformChoiceTender('etp_gpb', 'digest-deselected');
    $queryId = $this->actingAs($user)->postJson('/queries', platformChoicePayload(null))->assertCreated()->json('query.id');
    $this->patchJson('/queries/'.$queryId, platformChoicePayload(['workspace_ru']))->assertOk();
    NotificationPreference::query()->updateOrCreate(['user_id' => $user->id], [
        'digest_enabled' => true, 'digest_time' => '09:00', 'timezone' => 'Europe/Moscow',
    ]);
    $this->artisan('notifications:send-due-digests')->assertSuccessful();
    $digest = NotificationDelivery::query()->where('user_id', $user->id)->where('type', 'tender_digest')->sole();
    expect($digest->payload['count'])->toBe(1)
        ->and($digest->payload['tenders'][0]['url'])->toContain('workspace_ru');
});

function platformChoiceUser(): User
{
    $user = User::factory()->create(['telegram_id' => (string) random_int(100000000, 999999999)]);
    Entitlement::query()->create([
        'user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->basic()->id,
        'code' => 'active_queries', 'value' => 3,
        'status' => 'active', 'starts_at' => now()->subHour(), 'ends_at' => now()->addMonth(),
    ]);

    return $user;
}

function platformChoicePayload(?array $platforms): array
{
    return ['name' => 'Разработка сайта', 'keywords' => ['разработка', 'сайта'],
        'filters' => ['source' => ['rostender_template_id' => null, 'platforms' => $platforms]]];
}

function platformChoiceTender(string $source, string $key): Tender
{
    $feed = SourceFeed::query()->where('source', $source)->firstOrFail();
    $url = 'https://example.test/'.$source.'/'.$key;
    $item = SourceFeedItem::query()->create([
        'source_feed_id' => $feed->id, 'external_id' => $key, 'canonical_url' => $url,
        'url_hash' => hash('sha256', $url), 'title' => 'Разработка сайта для компании',
        'content_hash' => hash('sha256', $key), 'published_at' => now()->subDay(), 'discovered_at' => now(),
    ]);

    return Tender::query()->create([
        'source' => $source, 'source_feed_item_id' => $item->id, 'external_id' => $key,
        'canonical_url' => $url, 'canonical_url_hash' => hash('sha256', $url),
        'title' => 'Разработка сайта для компании', 'budget_amount' => '1500000', 'currency' => 'RUB',
        'published_at' => now()->subDay(), 'deadline_at' => now()->addDays(2),
    ]);
}
