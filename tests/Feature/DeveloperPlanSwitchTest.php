<?php

use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\AccessService;
use App\Services\DeveloperPlanService;
use App\Services\PlanCatalog;
use App\Services\QueryLimitReachedException;
use App\Services\SearchQueryService;
use App\Services\TenderAnalyticsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set(['tender.local_mvp_full_access.enabled' => false, 'tender.local_mvp_operator.enabled' => false]);
    Http::preventStrayRequests();
    Queue::fake();
});

it('switches the owner between three real server-side limits and analytics levels and restores original access', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Проверка', 'keywords' => ['сайт'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => 'developer-card', 'title' => 'Разработка сайта',
        'canonical_url' => 'https://example.test/developer', 'canonical_url_hash' => hash('sha256', 'developer'),
        'published_at' => now()->subDay(), 'deadline_at' => now()->addDays(5)]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);
    foreach (['basic' => 3, 'pro' => 10, 'ultra' => 30] as $code => $limit) {
        $this->actingAs($user->fresh())->put('/profile/developer-plan', ['plan_code' => $code])->assertRedirect('/profile');
        $current = $user->fresh();
        $access = app(AccessService::class)->snapshotFor($current);
        expect($current->developer_plan_code)->toBe($code)->and($current->role)->toBe(UserRole::SuperAdmin)
            ->and($access->planCode)->toBe($code)->and($access->activeQueryLimit)->toBe($limit)->and($access->mode)->toBe('developer');
        $this->actingAs($current)->get('/profile')->assertInertia(fn (Assert $p) => $p
            ->where('developerMode.selected_plan', $code)->has('developerMode.plans', 3)
            ->where('auth.access.plan_code', $code)->where('auth.access.active_query_limit', $limit));
        foreach (['', '/work'] as $suffix) {
            $this->get('/tenders/'.$tender->id.$suffix)->assertInertia(function (Assert $p) use ($code): void {
                $p->where('tender.analytics.tier', $code);
                $code === 'basic' ? $p->missing('tender.analytics.pro') : $p->has('tender.analytics.pro');
                $code === 'ultra' ? $p->has('tender.analytics.ultra') : $p->missing('tender.analytics.ultra');
            });
        }
        $this->get('/operations')->assertOk();
    }
    $this->put('/profile/developer-plan', ['plan_code' => null])->assertRedirect('/profile');
    $access = app(AccessService::class)->snapshotFor($user->fresh());
    expect($access->mode)->toBe('owner')->and($access->activeQueryLimit)->toBeNull()
        ->and(app(TenderAnalyticsService::class)->tierFor($user->fresh()))->toBe('ultra');
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('entitlements', 0);
    $this->assertDatabaseCount('search_queries', 1);
    Http::assertNothingSent();
});

it('allows an active Developer grant to switch without changing its term subscription or administrative role', function () {
    [$user, $grant] = developerSwitchGrant(true);
    $original = $grant->only(['plan_id', 'value', 'status', 'ends_at', 'metadata']);
    $this->actingAs($user)->putJson('/profile/developer-plan', ['plan_code' => 'ultra'])->assertRedirect('/profile');
    $access = app(AccessService::class)->snapshotFor($user->fresh());
    expect($access->planCode)->toBe('ultra')->and($access->activeQueryLimit)->toBe(30)
        ->and($access->endsAt)->toBe($grant->ends_at->toAtomString())
        ->and($user->fresh()->role)->toBe(UserRole::Subscriber)
        ->and($grant->refresh()->only(array_keys($original)))->toEqual($original);
    $this->actingAs($user->fresh())->get('/operations')->assertForbidden();
    $this->put('/profile/developer-plan', ['plan_code' => null])->assertRedirect('/profile');
    expect(app(AccessService::class)->snapshotFor($user->fresh())->planCode)->toBe('pro');
    $this->assertDatabaseCount('subscriptions', 1);
    $this->assertDatabaseCount('entitlements', 1);
});

it('hides and rejects switching for ordinary paid or manually granted accounts and ignores forged selections', function () {
    [$user] = developerSwitchGrant(false);
    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $p) => $p->where('developerMode', null));
    $this->put('/profile/developer-plan', ['plan_code' => 'ultra'])->assertForbidden();
    $user->forceFill(['developer_plan_code' => 'ultra'])->save();
    expect(app(AccessService::class)->snapshotFor($user->fresh())->planCode)->toBe('pro');
    $grant = Entitlement::query()->where('user_id', $user->id)->sole();
    $grant->update(['metadata' => ['developer_mode' => true]]);
    $grant->subscription->update(['source' => 'trial']);
    expect(app(DeveloperPlanService::class)->allowed($user->fresh()))->toBeFalse();
});

it('removes developer overrides when the grant expires or is revoked', function (string $change) {
    [$user, $grant] = developerSwitchGrant(true);
    $user->forceFill(['developer_plan_code' => 'ultra'])->save();
    expect(app(AccessService::class)->snapshotFor($user->fresh())->planCode)->toBe('ultra');
    $grant->update($change === 'expired' ? ['ends_at' => now()->subSecond()] : ['status' => 'cancelled']);
    expect(app(DeveloperPlanService::class)->allowed($user->fresh()))->toBeFalse()
        ->and(app(AccessService::class)->snapshotFor($user->fresh())->planCode)->not->toBe('ultra');
    $this->actingAs($user->fresh())->put('/profile/developer-plan', ['plan_code' => 'basic'])->assertForbidden();
})->with(['expired', 'revoked']);

it('validates the requested plan and never changes another account or mass assigns developer settings', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $other = User::factory()->create();
    foreach ([[], ['plan_code' => 'invented'], ['plan_code' => ['ultra']]] as $payload) {
        $this->actingAs($owner)->putJson('/profile/developer-plan', $payload)->assertUnprocessable();
    }
    $this->putJson('/profile/developer-plan', ['plan_code' => 'basic', 'user_id' => $other->id, 'role' => 'subscriber'])->assertRedirect('/profile');
    expect($owner->fresh()->developer_plan_code)->toBe('basic')->and($owner->fresh()->role)->toBe(UserRole::SuperAdmin)
        ->and($other->fresh()->developer_plan_code)->toBeNull();
    $other->fill(['developer_plan_code' => 'ultra']);
    expect($other->developer_plan_code)->toBeNull();
});

it('enforces the selected Basic limit for an owner while preserving existing monitorings', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($owner)->put('/profile/developer-plan', ['plan_code' => 'basic'])->assertRedirect('/profile');
    $owner = $owner->fresh();
    $service = app(SearchQueryService::class);
    foreach (range(1, 3) as $index) {
        $service->create($owner, ['name' => 'Тест '.$index, 'keywords' => ['сайт']]);
    }
    expect(fn () => $service->create($owner, ['name' => 'Лишний', 'keywords' => ['сайт']]))
        ->toThrow(QueryLimitReachedException::class);
    expect(SearchQuery::query()->where('user_id', $owner->id)->where('status', 'active')->count())->toBe(3);
    $this->actingAs($owner)->put('/profile/developer-plan', ['plan_code' => 'pro'])->assertRedirect('/profile');
    $service->create($owner->fresh(), ['name' => 'Четвёртый', 'keywords' => ['сайт']]);
    expect(SearchQuery::query()->where('user_id', $owner->id)->where('status', 'active')->count())->toBe(4);
    $this->actingAs($owner->fresh())->put('/profile/developer-plan', ['plan_code' => 'basic'])->assertRedirect('/profile');
    expect(SearchQuery::query()->where('user_id', $owner->id)->where('status', 'active')->count())->toBe(4);
});

it('applies selected tariffs in local full-access mode and denies disabled test operator identities', function () {
    config()->set('tender.local_mvp_full_access.enabled', true);
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $owner->forceFill(['developer_plan_code' => 'basic'])->save();
    expect(app(AccessService::class)->snapshotFor($owner)->activeQueryLimit)->toBe(3)
        ->and(app(TenderAnalyticsService::class)->tierFor($owner))->toBe('basic');
    $blocked = User::factory()->create(['role' => UserRole::SuperAdmin, 'email' => 'local-mvp-operator@tenderfinder.invalid']);
    $this->actingAs($blocked)->put('/profile/developer-plan', ['plan_code' => 'ultra'])->assertForbidden();
});

function developerSwitchGrant(bool $developer): array
{
    $user = User::factory()->create();
    $plan = app(PlanCatalog::class)->pro();
    $subscription = Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'source' => 'admin_grant',
        'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5)]);
    $grant = Entitlement::query()->create(['user_id' => $user->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 10, 'starts_at' => now()->subDay(),
        'ends_at' => $subscription->ends_at, 'metadata' => ['developer_mode' => $developer]]);

    return [$user, $grant];
}
