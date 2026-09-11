<?php

use App\Enums\QueryStatus;
use App\Jobs\MatchRostenderTender;
use App\Jobs\PollRostenderTemplate;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\RostenderApiUsage;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\RostenderApiClient;
use App\Services\RostenderQuotaGuard;
use App\Services\RostenderTemplateFeedService;
use App\Services\TenderMatchingService;
use App\Services\TenderSourceImportService;
use App\Tenders\RostenderAccessDisabledException;
use App\Tenders\RostenderQuotaExceededException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('tender.rostender.enabled', true);
    config()->set('tender.rostender.public_distribution_approved', true);
    config()->set('tender.rostender.api_key', 'testing-key');
    config()->set('tender.rostender.daily_quota_limit', 200);
    config()->set('tender.rostender.daily_quota_reserve', 20);
    config()->set('tender.rostender.max_details_per_poll', 20);
});

it('uses the documented saved-template and detail endpoints with a server header', function () {
    Http::fake([
        'https://rostender.info/api/tenders/get/template/42*' => Http::response([
            'success' => true,
            'data' => [['id' => 1001]],
            '_meta' => ['totalCount' => 1, 'pageCount' => 1],
        ]),
        'https://rostender.info/api/tenders/get/1001' => Http::response(rostenderDetail(1001)),
    ]);

    $client = app(RostenderApiClient::class);
    $page = $client->template(42);
    $detail = $client->tender(1001);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->id)->toBe(1001)
        ->and($detail->externalId)->toBe('1001')
        ->and($detail->externalUpdatedAt?->format('Y-m-d'))->toBe('2026-09-01');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rostender.info/api/tenders/get/template/42?page=1&sort=new-first'
        && $request->hasHeader('X-API-KEY', 'testing-key'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rostender.info/api/tenders/get/1001'
        && $request->hasHeader('X-API-KEY', 'testing-key'));
});

it('does not send an API request while either legal gate is disabled', function () {
    config()->set('tender.rostender.public_distribution_approved', false);
    Http::fake();

    expect(fn () => app(RostenderApiClient::class)->template(42))
        ->toThrow(RostenderAccessDisabledException::class);
    Http::assertNothingSent();
});

it('reserves normal polling capacity, counts only successful calls, and keeps the reserve', function () {
    config()->set('tender.rostender.daily_quota_limit', 3);
    config()->set('tender.rostender.daily_quota_reserve', 1);
    $guard = app(RostenderQuotaGuard::class);

    $first = $guard->reserve();
    $first->settle(true);
    $second = $guard->reserve();
    $second->settle(false);

    $third = $guard->reserve();
    $third->settle(true);

    expect(fn () => $guard->reserve())->toThrow(RostenderQuotaExceededException::class);
    expect(RostenderApiUsage::query()->sole()->successful_requests)->toBe(2)
        ->and(RostenderApiUsage::query()->sole()->in_flight_requests)->toBe(0);
});

it('deduplicates a saved template and applies the plan source-monitor limit', function () {
    config()->set('tender.rostender.basic_active_monitor_limit', 1);
    config()->set('tender.rostender.basic_poll_interval_seconds', 86400);
    $user = User::factory()->create();
    $plan = Plan::query()->create(['code' => 'basic', 'name' => 'Basic', 'is_active' => true, 'limits' => []]);
    Entitlement::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => 'active',
        'value' => 3,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
    ]);
    $first = rostenderQuery($user, 'Поставка');
    $second = rostenderQuery($user, 'Монтаж');
    $service = app(RostenderTemplateFeedService::class);

    $feed = $service->attach($first, 42);

    expect($service->attach($first, 42)->id)->toBe($feed->id)
        ->and(SourceFeed::query()->where('source', 'rostender')->count())->toBe(1)
        ->and($feed->poll_interval_seconds)->toBe(86400);
    expect(fn () => $service->attach($second, 43))->toThrow(RuntimeException::class, 'rostender_monitoring_limit_reached');
});

it('connects a users monitoring to an approved RosTender template and queues a manual refresh', function () {
    Queue::fake();
    config()->set('tender.rostender.basic_active_monitor_limit', 1);
    config()->set('tender.rostender.basic_manual_checks_per_day', 1);
    config()->set('tender.rostender.basic_poll_interval_seconds', 3600);
    Http::fake([
        'https://rostender.info/api/tenders/get/templates' => Http::response([
            'success' => true,
            'data' => [['id' => 42, 'name' => 'Разработка сайтов']],
        ]),
    ]);

    $user = User::factory()->create();
    $plan = Plan::query()->create(['code' => 'basic', 'name' => 'Basic', 'is_active' => true, 'limits' => []]);
    Entitlement::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => 'active',
        'value' => 3,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
    ]);

    $queryId = $this->actingAs($user)
        ->postJson('/queries', [
            'keywords' => ['разработка', 'сайта'],
            'filters' => ['source' => ['rostender_template_id' => 42]],
        ])
        ->assertCreated()
        ->json('query.id');

    $feed = SourceFeed::query()->where('source', 'rostender')->sole();
    expect($feed->source_identifier)->toBe(42)
        ->and($feed->poll_interval_seconds)->toBe(3600);
    $this->assertDatabaseHas('rostender_feed_search_query', [
        'source_feed_id' => $feed->id,
        'search_query_id' => $queryId,
    ]);

    $this->actingAs($user)
        ->postJson("/queries/{$queryId}/run")
        ->assertStatus(202)
        ->assertJsonPath('queued', true);

    Queue::assertPushed(PollRostenderTemplate::class, fn (PollRostenderTemplate $job): bool => $job->feedId === $feed->id);
});

it('imports new detail cards once and scopes matching to monitorings linked to the template', function () {
    Queue::fake();
    $feed = SourceFeed::query()->create([
        'source' => 'rostender',
        'source_identifier' => 42,
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/42',
        'url_hash' => hash('sha256', 'rostender:template:42'),
        'status' => 'active',
        'poll_interval_seconds' => 86400,
    ]);
    $linkedUser = User::factory()->create();
    $otherUser = User::factory()->create();
    $linkedQuery = rostenderQuery($linkedUser, 'Поставка');
    $otherQuery = rostenderQuery($otherUser, 'Поставка');
    RostenderFeedSearchQuery::query()->create([
        'source_feed_id' => $feed->id,
        'search_query_id' => $linkedQuery->id,
    ]);

    fakeRostenderTemplateWithOneDetail(42, 1001);
    (new PollRostenderTemplate($feed->id))->handle(app(RostenderApiClient::class), app(TenderSourceImportService::class));

    $tender = Tender::query()->where('source', 'rostender')->sole();
    expect($tender->external_id)->toBe('1001')
        ->and($tender->details_fetched_at)->not->toBeNull()
        ->and($tender->external_updated_at?->format('Y-m-d'))->toBe('2026-09-01')
        ->and($tender->metadata['rostender']['stage'])->toBe('Подача заявок');
    Queue::assertPushed(MatchRostenderTender::class, fn (MatchRostenderTender $job): bool => $job->queueNotifications === false);

    (new MatchRostenderTender($tender->id, $feed->id, false))->handle(app(TenderMatchingService::class));
    expect(TenderQueryMatch::query()->where('search_query_id', $linkedQuery->id)->count())->toBe(1)
        ->and(TenderQueryMatch::query()->where('search_query_id', $otherQuery->id)->count())->toBe(0);

    Http::fake([
        'https://rostender.info/api/tenders/get/template/42*' => Http::response([
            'success' => true,
            'data' => [['id' => 1001]],
            '_meta' => ['totalCount' => 1, 'pageCount' => 1],
        ]),
    ]);
    (new PollRostenderTemplate($feed->id))->handle(app(RostenderApiClient::class), app(TenderSourceImportService::class));

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/1001'));
});

/** @return array{success: bool, data: array<string, mixed>} */
function rostenderDetail(int $id): array
{
    return [
        'success' => true,
        'data' => [
            'id' => $id,
            'url' => 'https://rostender.info/tender/'.$id,
            'dts' => '2026-08-30',
            'dte-formatted' => '2026-09-30 12:00:00',
            'updated_at' => '2026-09-01',
            'price' => ['value' => 1500000, 'currency' => 'RUB'],
            'stage' => 'Подача заявок',
            'place' => 'Москва',
            'regions' => ['Москва'],
            'descr' => 'Поставка серверов',
            'eis' => '01234567890123456789',
            'customer' => ['name' => 'Заказчик'],
            'files' => ['items' => []],
        ],
    ];
}

function fakeRostenderTemplateWithOneDetail(int $templateId, int $tenderId): void
{
    Http::fake([
        'https://rostender.info/api/tenders/get/template/'.$templateId.'*' => Http::response([
            'success' => true,
            'data' => [['id' => $tenderId]],
            '_meta' => ['totalCount' => 1, 'pageCount' => 1],
        ]),
        'https://rostender.info/api/tenders/get/'.$tenderId => Http::response(rostenderDetail($tenderId)),
    ]);
}

function rostenderQuery(User $user, string $keyword): SearchQuery
{
    return SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => $keyword,
        'keywords' => [$keyword],
        'status' => QueryStatus::Active,
        'monitoring_started_at' => now()->subMinute(),
    ]);
}
