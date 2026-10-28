<?php

use App\Enums\AccessState;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\AccessService;
use App\Services\FeedAccessService;
use App\Services\SubscriptionCatalog;
use App\Services\TenderMatchingService;
use App\Services\TrialLifecycleService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow('2028-01-31 09:00:00');
    Queue::fake();
    Http::preventStrayRequests();
    config()->set([
        'tender.payments.demo_enabled' => true,
        'tender.temporary_full_access_until' => null,
        'tender.local_mvp_full_access.enabled' => false,
        'tender.legal.documents_published' => true,
        'tender.legal.offer_url' => 'https://example.test/offer',
        'tender.legal.offer_version' => 'demo-test',
        'tender.legal.privacy_url' => 'https://example.test/privacy',
        'tender.legal.privacy_version' => 'demo-test',
    ]);
});
afterEach(fn () => Carbon::setTestNow());

function demoGuest(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_browser_guest' => true, 'is_demo_guest' => true, 'telegram_id' => null])->save();

    return $user;
}

function demoOrderPayload(string $code = 'pro', int $months = 1): array
{
    return ['plan_code' => $code, 'duration_months' => $months, 'idempotency_key' => (string) Str::uuid()];
}

function expiredDemoGuest(): User
{
    $user = demoGuest();
    test()->actingAs($user)->postJson('/consents', ['documents' => ['offer', 'privacy']])->assertCreated();
    test()->postJson('/demo/expire')->assertOk();

    return $user->refresh();
}

it('keeps all nine authoritative prices and month-end boundaries', function () {
    $catalog = app(SubscriptionCatalog::class);
    expect(array_column($catalog->all(), 'amount_minor'))->toBe([34900, 98900, 376900, 59900, 169900, 646900, 89900, 255900, 970900]);
    foreach ([['2028-01-31 09:00:00', 1, '2028-02-29 09:00:00'], ['2027-01-31 09:00:00', 1, '2027-02-28 09:00:00'], ['2028-02-29 09:00:00', 12, '2029-02-28 09:00:00'], ['2028-01-31 09:00:00', 3, '2028-04-30 09:00:00'], ['2028-04-30 09:00:00', 1, '2028-05-30 09:00:00']] as [$start, $months, $end]) {
        expect($catalog->endAt(Carbon::parse($start, 'UTC'), $months)->format('Y-m-d H:i:s'))->toBe($end);
    }
});

it('isolates a new browser guest from the expired public timer and starts trial only once', function () {
    config()->set('tender.temporary_full_access_until', '2020-01-01T00:00:00Z');
    $this->post('/demo/session')->assertRedirect('/consents');
    $user = auth()->user();
    expect($user->is_demo_guest)->toBeTrue()->and($user->telegram_id)->toBeNull();
    $this->postJson('/trial/start')->assertUnprocessable();
    $this->postJson('/consents', ['documents' => ['offer', 'privacy']])->assertCreated()->assertJsonPath('access.state', 'trialing');
    $this->postJson('/trial/start')->assertUnprocessable();
    $this->post('/demo/session')->assertConflict();
    expect(Subscription::query()->count())->toBe(1);
    $this->get('/plans')->assertOk();
    $this->get('/operations')->assertForbidden();
});

it('closes HTTP actions and matching at exactly 72 hours without the scheduler', function () {
    $user = demoGuest();
    $this->actingAs($user)->postJson('/consents', ['documents' => ['offer', 'privacy']])->assertCreated();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Test', 'keywords' => ['сайт'], 'status' => QueryStatus::Active]);
    Carbon::setTestNow(now()->addHours(72)->subSecond());
    expect(app(AccessService::class)->hasActiveAccess($user))->toBeTrue();
    Carbon::setTestNow(now()->addSecond());
    foreach (['/queries', '/queries/quick', '/queries/'.$query->id.'/run', '/teams', '/participation/remove', '/calendar/subscription', '/local/mvp/tenders/export'] as $path) {
        $this->postJson($path)->assertForbidden();
    }
    $this->getJson('/calendar/export')->assertForbidden();
    $this->getJson('/participation/analytics/export')->assertForbidden();
    expect($query->refresh()->status)->toBe(QueryStatus::Frozen);
    $this->get('/plans')->assertOk();
    expect(Subscription::query()->first()->status)->toBe(SubscriptionStatus::Active); // scheduler has not run
});

it('atomically grants the selected plan once and preserves paused queries', function (string $code, int $months, int $amount, int $limit) {
    $user = expiredDemoGuest();
    $paused = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Paused', 'keywords' => ['сайт'], 'status' => QueryStatus::Paused]);
    $frozen = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Frozen', 'keywords' => ['сайт'], 'status' => QueryStatus::Frozen]);
    $payload = demoOrderPayload($code, $months);
    $order = $this->postJson('/demo/orders', $payload)->assertOk()->assertJsonPath('order.amount_minor', $amount)->json('order');
    $this->postJson('/demo/orders', $payload)->assertOk()->assertJsonPath('order.id', $order['id']);
    $this->postJson('/demo/orders', demoOrderPayload($code, $months))->assertOk()->assertJsonPath('order.id', $order['id']);
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertOk()->assertJsonPath('access.plan_code', $code)->assertJsonPath('access.active_query_limit', $limit);
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertOk();
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'canceled'])->assertOk()->assertJsonPath('order.status', 'succeeded');
    expect(SubscriptionOrder::query()->count())->toBe(1)
        ->and(Subscription::query()->where('source', 'demo')->count())->toBe(1)
        ->and(DB::table('subscription_order_events')->count())->toBe(2)
        ->and($paused->refresh()->status)->toBe(QueryStatus::Paused)
        ->and($frozen->refresh()->status)->toBe(QueryStatus::Frozen);
    $this->postJson('/queries/'.$frozen->id.'/resume')->assertOk();
    expect($frozen->refresh()->monitoring_started_at->equalTo(now()))->toBeTrue();
    $this->get('/catalog')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('PriceCatalog')->where('enabled', $code !== 'basic'));
    $this->postJson('/demo/orders', demoOrderPayload())->assertConflict();
    Carbon::setTestNow(Carbon::parse($order['ends_at']));
    expect(app(AccessService::class)->snapshotFor($user)->state)->toBe(AccessState::Expired);
    app(TrialLifecycleService::class)->processDue();
    expect(Subscription::query()->where('source', 'demo')->first()->status)->toBe(SubscriptionStatus::Expired);
    $this->postJson('/demo/orders', demoOrderPayload())->assertOk();
    Http::assertNothingSent();
})->with([
    ['basic', 1, 34900, 3], ['basic', 3, 98900, 3], ['basic', 12, 376900, 3],
    ['pro', 1, 59900, 10], ['pro', 3, 169900, 10], ['pro', 12, 646900, 10],
    ['ultra', 1, 89900, 30], ['ultra', 3, 255900, 30], ['ultra', 12, 970900, 30],
]);

it('preserves pending state and makes failure and cancellation terminal', function (string $outcome) {
    $user = expiredDemoGuest();
    $order = $this->postJson('/demo/orders', demoOrderPayload())->assertOk()->json('order');
    $path = '/demo/orders/'.$order['id'];
    $this->getJson($path.'?success=true&status=succeeded')->assertOk()->assertJsonPath('order.status', 'pending');
    $this->postJson($path.'/confirm', ['outcome' => $outcome])->assertOk()->assertJsonPath('order.status', $outcome);
    expect(app(AccessService::class)->hasActiveAccess($user))->toBeFalse();
    if ($outcome !== 'pending') {
        $this->postJson($path.'/confirm', ['outcome' => 'succeeded'])->assertOk()->assertJsonPath('order.status', $outcome);
        $this->postJson('/demo/orders', demoOrderPayload())->assertOk();
        expect(SubscriptionOrder::query()->count())->toBe(2);
    }
})->with(['pending', 'failed', 'canceled']);

it('rejects stale quotes, forged prices or owners, invalid periods, and reused keys with changed terms', function () {
    expiredDemoGuest();
    $payload = demoOrderPayload();
    foreach (['amount_minor' => 1, 'user_id' => 123, 'status' => 'succeeded', 'active_query_limit' => 999, 'currency' => 'USD', 'order_id' => 'fake'] as $field => $value) {
        $this->postJson('/demo/orders', [...$payload, $field => $value])->assertUnprocessable();
    }
    $this->postJson('/demo/orders', [...$payload, 'duration_months' => 30])->assertUnprocessable();
    $this->postJson('/demo/orders', [...$payload, 'plan_code' => 'owner'])->assertUnprocessable();
    $order = $this->postJson('/demo/orders', $payload)->assertOk()->json('order');
    $this->postJson('/demo/orders', [...$payload, 'plan_code' => 'ultra'])->assertConflict();
    $this->postJson('/demo/orders', demoOrderPayload('ultra'))->assertConflict();
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded', 'plan_code' => 'ultra'])->assertUnprocessable();
    Carbon::setTestNow(now()->addMinutes(30));
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertOk()->assertJsonPath('order.status', 'canceled');
    expect(Subscription::query()->where('source', 'demo')->count())->toBe(0);
});

it('does not authorize ordinary, owner, or other guest accounts to confirm another order', function () {
    expiredDemoGuest();
    $order = $this->postJson('/demo/orders', demoOrderPayload())->assertOk()->json('order');
    foreach ([User::factory()->create(), User::factory()->create(['role' => UserRole::SuperAdmin])] as $user) {
        $this->actingAs($user)->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertForbidden();
        $this->postJson('/demo/expire')->assertForbidden();
    }
    $this->actingAs(demoGuest())->getJson('/demo/orders/'.$order['id'])->assertNotFound();
    $this->postJson('/demo/orders/'.$order['id'].'/confirm', ['outcome' => 'succeeded'])->assertNotFound();
    $this->postJson('/demo/orders', demoOrderPayload())->assertConflict();
});

it('requires an expiring signed reviewer session outside local testing, and obeys the kill switch', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    app()->detectEnvironment(fn () => 'production');
    $this->get('/demo')->assertForbidden();
    $this->post('/demo/session')->assertForbidden();
    $this->get('/demo/reviewer?expires=9999999999&signature=forged')->assertForbidden();
    $url = URL::temporarySignedRoute('demo.reviewer', now()->addHour());
    $this->get($url)->assertRedirect('/demo');
    $this->post('/demo/session')->assertRedirect('/consents');
    Carbon::setTestNow(now()->addHour());
    $this->postJson('/demo/expire')->assertForbidden();
    $this->get($url)->assertForbidden();
    config()->set('tender.payments.demo_enabled', false);
    $this->get('/demo')->assertNotFound();
    $this->postJson('/demo/orders', demoOrderPayload())->assertNotFound();
});

it('retains owner and admin grants when an old trial expires', function () {
    $user = expiredDemoGuest();
    $this->postJson('/demo/orders', demoOrderPayload())->assertOk();
    $order = SubscriptionOrder::query()->firstOrFail();
    $trial = Subscription::query()->firstOrFail();
    $grant = Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $trial->plan_id, 'source' => SubscriptionSource::AdminGrant, 'status' => SubscriptionStatus::Active, 'starts_at' => now(), 'ends_at' => null]);
    Entitlement::query()->create(['user_id' => $user->id, 'subscription_id' => $grant->id, 'plan_id' => $trial->plan_id, 'code' => 'active_queries', 'value' => 3, 'status' => SubscriptionStatus::Active, 'starts_at' => now(), 'ends_at' => null]);
    $this->postJson('/demo/orders/'.$order->id.'/confirm', ['outcome' => 'succeeded'])->assertConflict();
    app(TrialLifecycleService::class)->processDue();
    expect($grant->refresh()->status)->toBe(SubscriptionStatus::Active);
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    expect(app(AccessService::class)->snapshotFor($owner)->mode)->toBe('owner');
});

it('stops matching scoped feed polling and queued deliveries at expiry without scheduler', function () {
    $user = demoGuest();
    $this->actingAs($user)->postJson('/consents', ['documents' => ['offer', 'privacy']])->assertCreated();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Test', 'keywords' => ['сайт'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => 'demo-match', 'title' => 'Сайт', 'canonical_url' => 'https://example.test/demo', 'canonical_url_hash' => hash('sha256', 'demo')]);
    $delivery = NotificationDelivery::query()->create(['user_id' => $user->id, 'type' => 'tender_card', 'status' => 'failed', 'failure_code' => 'telegram_network_unavailable', 'idempotency_key' => 'old-demo', 'payload' => [], 'scheduled_at' => now()]);
    $feed = SourceFeed::query()->create(['source' => 'rostender', 'source_identifier' => 42, 'canonical_url' => 'https://example.test/feed', 'url_hash' => hash('sha256', 'demo-feed'), 'status' => 'active', 'poll_interval_seconds' => 3600]);
    RostenderFeedSearchQuery::query()->create(['source_feed_id' => $feed->id, 'search_query_id' => $query->id]);
    Carbon::setTestNow(now()->addHours(72));
    expect(app(FeedAccessService::class)->mayPoll($feed))->toBeFalse();
    expect(app(TenderMatchingService::class)->matchTenderForQueries($tender, [$query]))->toBe(0);
    expect($query->refresh()->status)->toBe(QueryStatus::Frozen)
        ->and($delivery->refresh()->status->value)->toBe('skipped');
    expect(TenderQueryMatch::query()->count())->toBe(0);
});

it('keeps demo guests on a real trial even while a public full-access window is active', function () {
    config()->set('tender.temporary_full_access_until', now()->addDays(2)->format('Y-m-d\TH:i:s\Z'));
    $user = demoGuest();
    $this->actingAs($user)->postJson('/consents', ['documents' => ['offer', 'privacy']])->assertCreated()->assertJsonPath('access.state', 'trialing')->assertJsonPath('access.active_query_limit', 3);
    expect($user->refresh()->trial_used_at)->not->toBeNull();
    $this->postJson('/team-invitations/any-token')->assertForbidden();
});

it('recovers the newest order when several attempts occur within the same second', function () {
    expiredDemoGuest();
    $first = $this->postJson('/demo/orders', demoOrderPayload('basic'))->json('order');
    $this->postJson('/demo/orders/'.$first['id'].'/confirm', ['outcome' => 'failed'])->assertOk();
    $next = $this->postJson('/demo/orders', demoOrderPayload('ultra', 12))->json('order');
    $this->get('/demo')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('DemoCheckout')->where('order.id', $next['id'])->where('order.status', 'pending'));
});
