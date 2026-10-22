<?php

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the server trial term and an empty Mini App purchase surface', function () {
    Carbon::setTestNow('2026-10-22 12:00:00');
    $user = User::factory()->create();
    $plan = Plan::query()->create(['code' => 'basic', 'name' => 'Basic', 'is_active' => true, 'limits' => ['active_queries' => 3]]);
    $subscription = Subscription::query()->create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'source' => SubscriptionSource::Trial,
        'status' => SubscriptionStatus::Active, 'starts_at' => now()->subHours(48),
        'ends_at' => now()->addHours(24),
    ]);
    Entitlement::query()->create([
        'user_id' => $user->id, 'subscription_id' => $subscription->id,
        'plan_id' => $plan->id, 'code' => 'active_queries', 'value' => 3,
        'status' => SubscriptionStatus::Active, 'starts_at' => now()->subHours(48),
        'ends_at' => now()->addHours(24),
    ]);

    config()->set('tender.payments.yookassa.enabled', true);
    $this->actingAs($user)->get('/plans')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Plans')
        ->where('auth.access.state', 'trialing')
        ->where('auth.access.active_query_limit', 3)
        ->where('auth.access.ends_at', now()->addHours(24)->toAtomString())
        ->where('purchase.availability', 'unavailable')
        ->where('purchase.plans', [])
        ->where('purchase.checkout', null));
});

it('preserves the last period after expiry and rejects a forged checkout success', function () {
    Carbon::setTestNow('2026-10-22 12:00:00');
    $user = User::factory()->create(['trial_used_at' => now()->subDays(4)]);
    $plan = Plan::query()->create(['code' => 'basic', 'name' => 'Basic', 'is_active' => true, 'limits' => ['active_queries' => 3]]);
    $subscription = Subscription::query()->create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'source' => SubscriptionSource::Trial,
        'status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(4),
        'ends_at' => now()->subDay(),
    ]);
    Entitlement::query()->create([
        'user_id' => $user->id, 'subscription_id' => $subscription->id,
        'plan_id' => $plan->id, 'code' => 'active_queries', 'value' => 3,
        'status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(4),
        'ends_at' => now()->subDay(),
    ]);

    $this->actingAs($user)->postJson('/billing/orders', [
        'plan' => 'pro', 'status' => 'succeeded', 'payment_id' => 'forged',
    ])->assertNotFound();
    $this->actingAs($user)->get('/plans')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Plans')
        ->where('auth.access.state', 'expired')
        ->where('auth.access.ends_at', now()->subDay()->toAtomString())
        ->where('purchase.availability', 'unavailable')
        ->where('purchase.plans', []));
    expect(app(AccessService::class)->hasActiveAccess($user))->toBeFalse()
        ->and(Payment::query()->count())->toBe(0);
});
