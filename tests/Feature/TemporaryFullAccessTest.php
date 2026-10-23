<?php

use App\Enums\AccessState;
use App\Enums\ConsentDocument;
use App\Enums\QueryStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccessService;
use App\Services\ConsentService;
use App\Services\PlanCatalog;
use App\Services\SearchQueryService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 10:00:00 UTC');
    config()->set([
        'tender.temporary_full_access_until' => '2026-10-25T10:00:00Z',
        'tender.legal.documents_published' => true,
        'tender.legal.offer_url' => 'https://example.test/offer',
        'tender.legal.offer_version' => '2026-08-25',
        'tender.legal.privacy_url' => 'https://example.test/privacy',
        'tender.legal.privacy_version' => '2026-08-25',
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('opens unlimited review access to consenting subscribers and restores normal rules at the deadline', function (): void {
    $subscriber = User::factory()->create(['trial_used_at' => now()->subWeek()]);
    app(ConsentService::class)->acceptCurrent($subscriber, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);

    for ($index = 0; $index < 30; $index++) {
        SearchQuery::query()->create([
            'user_id' => $subscriber->id,
            'name' => "Мониторинг {$index}",
            'keywords' => ['сайт'],
            'status' => QueryStatus::Active,
        ]);
    }
    SearchQuery::query()->create([
        'user_id' => $owner->id, 'name' => 'Владелец', 'keywords' => ['сайт'], 'status' => QueryStatus::Active,
    ]);

    $access = app(AccessService::class)->snapshotFor($subscriber);
    expect($access->state)->toBe(AccessState::Active)
        ->and($access->mode)->toBe('temporary_full_access')
        ->and($access->planCode)->toBe('ultra')
        ->and($access->activeQueryLimit)->toBeNull()
        ->and(app(AccessService::class)->snapshotFor($owner)->mode)->toBe('owner');

    app(SearchQueryService::class)->create($subscriber, ['name' => 'Мониторинг 31', 'keywords' => ['сервер']]);
    expect(SearchQuery::query()->where('user_id', $subscriber->id)->where('status', QueryStatus::Active)->count())->toBe(31);
    $this->actingAs($subscriber)->get('/operations')->assertForbidden();

    Carbon::setTestNow('2026-10-25 10:00:00 UTC');
    expect(app(AccessService::class)->snapshotFor($subscriber)->state)->toBe(AccessState::Expired);
    $this->artisan('access:reconcile-temporary-full-access')->assertSuccessful();
    expect(SearchQuery::query()->where('user_id', $subscriber->id)->where('status', QueryStatus::Active)->count())->toBe(0)
        ->and(SearchQuery::query()->where('user_id', $owner->id)->where('status', QueryStatus::Active)->count())->toBe(1);
});

it('creates an isolated browser guest with a cookie session and does not consume a trial', function (): void {
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('guestEntryEnabled', true));
    $this->post('/guest/session')->assertRedirect('/consents');

    $guest = User::query()->where('is_browser_guest', true)->sole();
    expect($guest->telegram_id)->toBeNull()
        ->and($guest->role)->toBe(UserRole::Subscriber)
        ->and(auth()->id())->toBe($guest->id);
    $this->get('/operations')->assertForbidden();

    $this->postJson('/consents', ['documents' => ['offer', 'privacy']])
        ->assertCreated()
        ->assertJsonPath('status', 'access_granted')
        ->assertJsonPath('access.mode', 'temporary_full_access')
        ->assertJsonPath('access.active_query_limit', null);
    expect($guest->fresh()->trial_used_at)->toBeNull()
        ->and(Entitlement::query()->where('user_id', $guest->id)->count())->toBe(0);
    $this->get('/dashboard')->assertOk();

    Carbon::setTestNow('2026-10-25 10:00:00 UTC');
    $this->withHeaders(['Accept' => 'text/html'])->get('/dashboard')->assertRedirect('/');
    expect(auth()->check())->toBeFalse();
    $this->post('/guest/session')->assertNotFound();
});

it('preserves paid access without renewed consent and freezes only monitoring above the normal limit', function (): void {
    $paid = User::factory()->create();
    $plan = app(PlanCatalog::class)->basic();
    $subscription = Subscription::query()->create([
        'user_id' => $paid->id, 'plan_id' => $plan->id, 'source' => 'admin_grant',
        'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek(),
    ]);
    Entitlement::query()->create([
        'user_id' => $paid->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 3,
        'starts_at' => now()->subDay(), 'ends_at' => $subscription->ends_at,
    ]);
    expect(app(AccessService::class)->snapshotFor($paid)->activeQueryLimit)->toBe(3);

    app(ConsentService::class)->acceptCurrent($paid, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    expect(app(AccessService::class)->snapshotFor($paid)->mode)->toBe('temporary_full_access');
    foreach (range(1, 5) as $index) {
        SearchQuery::query()->create([
            'user_id' => $paid->id, 'name' => "Мониторинг {$index}",
            'keywords' => ['сайт'], 'status' => QueryStatus::Active,
        ]);
    }

    Carbon::setTestNow('2026-10-25 10:00:00 UTC');
    $this->artisan('access:reconcile-temporary-full-access')->assertSuccessful();
    expect(SearchQuery::query()->where('user_id', $paid->id)->where('status', QueryStatus::Active)->count())->toBe(3)
        ->and(SearchQuery::query()->where('user_id', $paid->id)->where('status', QueryStatus::Frozen)->count())->toBe(2)
        ->and(app(AccessService::class)->snapshotFor($paid)->activeQueryLimit)->toBe(3);
});

it('keeps Telegram trial available after the review window without consuming it during review', function (): void {
    $user = User::factory()->create(['telegram_id' => '77112233']);
    $this->actingAs($user)->postJson('/consents', ['documents' => ['offer', 'privacy']])
        ->assertCreated()->assertJsonPath('status', 'access_granted');
    expect($user->fresh()->trial_used_at)->toBeNull();

    Carbon::setTestNow('2026-10-25 10:00:00 UTC');
    expect(app(AccessService::class)->snapshotFor($user)->state)->toBe(AccessState::Preview);
    $this->actingAs($user)->postJson('/trial/start')->assertCreated()->assertJsonPath('access.state', 'trialing');
});

it('keeps guest entry closed when the deadline is missing or invalid', function (): void {
    config()->set('tender.temporary_full_access_until', 'invalid');
    $this->post('/guest/session')->assertNotFound();
    $this->get('/')->assertInertia(fn ($page) => $page->where('guestEntryEnabled', false));
});
