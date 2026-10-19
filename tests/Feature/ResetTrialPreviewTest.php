<?php

use App\Enums\AccessState;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccessService;
use App\Services\PlanCatalog;
use App\Services\TelegramIdentityService;
use App\Telegram\VerifiedTelegramUser;

beforeEach(function (): void {
    config()->set('tender.local_mvp_full_access.enabled', false);
    config()->set('tender.local_mvp_operator.enabled', false);
});

it('resets an owner to first-start preview without deleting their access history', function () {
    $telegramId = '844023492';
    $user = User::factory()->create([
        'telegram_id' => $telegramId,
        'role' => UserRole::SuperAdmin,
        'trial_used_at' => now()->subWeek(),
    ]);
    $plan = app(PlanCatalog::class)->basic();

    foreach ([SubscriptionSource::Trial, SubscriptionSource::AdminGrant] as $source) {
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'source' => $source,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
        ]);
        Entitlement::query()->create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'code' => 'active_queries',
            'status' => SubscriptionStatus::Active,
            'value' => 3,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
        ]);
    }
    $query = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Разработка сайтов',
        'keywords' => ['сайт'],
        'status' => QueryStatus::Active,
    ]);

    config()->set('tender.telegram.superadmin_ids', $telegramId);
    $this->artisan('access:reset-trial-preview', ['telegram_id' => $telegramId, '--apply' => true])
        ->assertExitCode(1);
    expect($user->fresh()->role)->toBe(UserRole::SuperAdmin);

    config()->set('tender.telegram.superadmin_ids', '1310235757');
    $this->artisan('access:reset-trial-preview', ['telegram_id' => $telegramId])
        ->assertExitCode(0);
    expect($user->fresh()->role)->toBe(UserRole::SuperAdmin);

    $this->artisan('access:reset-trial-preview', ['telegram_id' => $telegramId, '--apply' => true])
        ->assertExitCode(0);
    expect($user->fresh()->role)->toBe(UserRole::Subscriber)
        ->and($user->fresh()->trial_used_at)->toBeNull()
        ->and(app(AccessService::class)->snapshotFor($user->fresh())->state)->toBe(AccessState::Preview)
        ->and(Entitlement::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Entitlement::query()->where('user_id', $user->id)->where('status', SubscriptionStatus::Expired)->count())->toBe(2)
        ->and(Subscription::query()->where('user_id', $user->id)->where('status', SubscriptionStatus::Expired)->count())->toBe(2)
        ->and($query->fresh()->status)->toBe(QueryStatus::Frozen);

    app(TelegramIdentityService::class)->findOrCreate(new VerifiedTelegramUser(
        $telegramId, 'Олег', null, null, 'ru', now()->timestamp,
    ));
    expect($user->fresh()->role)->toBe(UserRole::Subscriber);
});

it('refuses to reset access when a Stars subscription exists', function () {
    $user = User::factory()->create([
        'telegram_id' => '844023492',
        'role' => UserRole::SuperAdmin,
    ]);
    $plan = app(PlanCatalog::class)->basic();
    Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'source' => SubscriptionSource::TelegramStars,
        'status' => SubscriptionStatus::Expired,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subDay(),
    ]);
    config()->set('tender.telegram.superadmin_ids', '1310235757');

    $this->artisan('access:reset-trial-preview', [
        'telegram_id' => '844023492',
        '--apply' => true,
    ])->assertExitCode(1);

    expect($user->fresh()->role)->toBe(UserRole::SuperAdmin);
});
