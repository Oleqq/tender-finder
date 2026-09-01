<?php

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-02 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows super admins an anonymised marketing dashboard for the requested period', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $recent = marketingUser('marketing-recent', [
        'created_at' => now()->subDay(),
        'last_seen_at' => now()->subDay(),
        'trial_used_at' => now()->subDay(),
        'telegram_username' => 'private-handle',
    ]);
    $trialing = marketingUser('marketing-trial', [
        'created_at' => now()->subDays(10),
        'last_seen_at' => now()->subDays(2),
        'trial_used_at' => now()->subDays(2),
    ]);
    $paid = marketingUser('marketing-paid', [
        'created_at' => now()->subDays(50),
        'last_seen_at' => now()->subDay(),
        'trial_used_at' => now()->subDays(30),
    ]);
    $granted = marketingUser('marketing-granted', [
        'created_at' => now()->subDays(60),
        'trial_used_at' => now()->subDays(20),
    ]);
    marketingUser('marketing-preview', ['created_at' => now()->subDays(60)]);
    marketingUser('marketing-expired', [
        'created_at' => now()->subDays(60),
        'trial_used_at' => now()->subDays(40),
    ]);

    grantMarketingAccess($trialing, SubscriptionSource::Trial, now()->subDays(2));
    grantMarketingAccess($paid, SubscriptionSource::TelegramStars, now()->subDay());
    grantMarketingAccess($granted, SubscriptionSource::AdminGrant, now()->subDays(20));

    User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'telegram_id' => 'marketing-owner',
        'created_at' => now()->subDay(),
        'last_seen_at' => now()->subDay(),
    ]);
    User::factory()->create([
        'role' => UserRole::Subscriber,
        'telegram_id' => null,
        'created_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($admin)->get('/operations?period=7d');

    $response
        ->assertOk()
        ->assertDontSee('marketing-recent')
        ->assertDontSee('private-handle')
        ->assertInertia(fn (Assert $page) => $page
            ->component('OperationsDashboard')
            ->where('dashboard.period.key', '7d')
            ->where('dashboard.period.days', 7)
            ->where('dashboard.audience.total', 6)
            ->where('dashboard.audience.newUsers', 1)
            ->where('dashboard.audience.miniAppActive', 3)
            ->where('dashboard.activation.trialsStarted', 2)
            ->where('dashboard.activation.trialsTotal', 5)
            ->where('dashboard.activation.starsStarted', 1)
            ->where('dashboard.access.preview', 1)
            ->where('dashboard.access.trialing', 1)
            ->where('dashboard.access.paid', 1)
            ->where('dashboard.access.granted', 1)
            ->where('dashboard.access.expired', 2)
            ->where('dashboard.funnel.trialRate', 83.3)
            ->where('dashboard.funnel.paidRate', 20)
            ->where('dashboard.commerce.state', 'pending')
            ->missing('dashboard.telegram_id')
            ->missing('dashboard.telegramId')
            ->missing('dashboard.username')
            ->missing('dashboard.users')
            ->missing('dashboard.searchQueries')
            ->missing('dashboard.notifications')
            ->missing('dashboard.infrastructure')
            ->has('dashboard.series', 7)
            ->where('dashboard.series.4.trials', 1)
            ->where('dashboard.series.5.registrations', 1)
            ->where('dashboard.series.5.trials', 1)
            ->where('dashboard.series.5.starsStarts', 1));
});

it('uses a 30-day dashboard for an unsupported period and has no revenue data on an empty base', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($admin)
        ->get('/operations?period=all-time')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OperationsDashboard')
            ->where('dashboard.period.key', '30d')
            ->where('dashboard.period.days', 30)
            ->where('dashboard.audience.total', 0)
            ->where('dashboard.audience.miniAppActive', 0)
            ->where('dashboard.activation.starsStarted', 0)
            ->where('dashboard.funnel.trialRate', 0)
            ->where('dashboard.funnel.paidRate', 0)
            ->where('dashboard.commerce.state', 'pending')
            ->missing('dashboard.commerce.revenue')
            ->has('dashboard.series', 30));
});

it('supports a 90-day view and keeps the legacy address as a protected redirect', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    marketingUser('marketing-older-registration', [
        'created_at' => now()->subDays(60),
        'last_seen_at' => now()->subDays(60),
    ]);

    $this->actingAs($admin)
        ->get('/operations?period=90d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.period.key', '90d')
            ->where('dashboard.audience.newUsers', 1)
            ->has('dashboard.series', 90));

    $this->actingAs($admin)
        ->get('/operations-demo')
        ->assertRedirect('/operations');
});

it('does not allow subscribers to open marketing analytics or use the legacy address', function () {
    $subscriber = User::factory()->create(['telegram_id' => 'marketing-subscriber']);

    $this->actingAs($subscriber)->get('/operations')->assertForbidden();
    $this->actingAs($subscriber)->get('/operations-demo')->assertForbidden();
});

function marketingUser(string $telegramId, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'telegram_id' => $telegramId,
        'telegram_username' => null,
        'trial_used_at' => null,
        'last_seen_at' => null,
    ], $attributes));
}

function grantMarketingAccess(User $user, SubscriptionSource $source, Carbon $startsAt): void
{
    $plan = Plan::query()->firstOrCreate(
        ['code' => 'analytics-basic'],
        ['name' => 'Analytics Basic', 'is_active' => true, 'limits' => ['active_queries' => 3]],
    );
    $subscription = Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'source' => $source,
        'status' => SubscriptionStatus::Active,
        'starts_at' => $startsAt,
        'ends_at' => now()->addDay(),
    ]);
    Entitlement::query()->create([
        'user_id' => $user->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => $startsAt,
        'ends_at' => now()->addDay(),
    ]);
}
