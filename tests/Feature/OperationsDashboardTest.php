<?php

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows super admins aggregate access states without exposing customer data', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $trialUser = telegramCustomer('analytics-trial', true);
    $paidUser = telegramCustomer('analytics-paid');
    $grantedUser = telegramCustomer('analytics-granted');
    $overlapUser = telegramCustomer('analytics-overlap', true);
    telegramCustomer('analytics-preview');
    telegramCustomer('analytics-expired', true);

    grantAnalyticsAccess($trialUser, SubscriptionSource::Trial);
    grantAnalyticsAccess($paidUser, SubscriptionSource::TelegramStars);
    grantAnalyticsAccess($grantedUser, SubscriptionSource::AdminGrant);
    grantAnalyticsAccess($overlapUser, SubscriptionSource::Trial);
    grantAnalyticsAccess($overlapUser, SubscriptionSource::TelegramStars, 2);

    $this->actingAs($admin)
        ->get('/operations-demo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OperationsDemo')
            ->where('accessMetrics.registered', 6)
            ->where('accessMetrics.preview', 1)
            ->where('accessMetrics.trialing', 1)
            ->where('accessMetrics.paid', 2)
            ->where('accessMetrics.granted', 1)
            ->where('accessMetrics.expired', 1));
});

it('does not allow subscribers to open access analytics', function () {
    $this->actingAs(User::factory()->create(['telegram_id' => 'analytics-subscriber']))
        ->get('/operations-demo')
        ->assertForbidden();
});

function telegramCustomer(string $telegramId, bool $usedTrial = false): User
{
    return User::factory()->create([
        'telegram_id' => $telegramId,
        'trial_used_at' => $usedTrial ? now()->subDay() : null,
    ]);
}

function grantAnalyticsAccess(User $user, SubscriptionSource $source, int $endsInDays = 1): void
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
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays($endsInDays),
    ]);
    Entitlement::query()->create([
        'user_id' => $user->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDays($endsInDays),
    ]);
}
