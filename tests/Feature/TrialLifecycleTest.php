<?php

use App\Enums\NotificationStatus;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\Plan;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\AccessService;
use App\Services\NotificationService;
use App\Services\TelegramBotClient;
use App\Services\TrialLifecycleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('queues each trial reminder once at the correct remaining-time window', function () {
    Queue::fake();
    Carbon::setTestNow('2026-08-25 10:00:00');
    $trial = trialAccess(endsAt: now()->addHours(23));

    app(TrialLifecycleService::class)->processDue();
    app(TrialLifecycleService::class)->processDue();

    expect(NotificationDelivery::query()->where('user_id', $trial['user']->id)->count())->toBe(1)
        ->and(NotificationDelivery::query()->value('type'))->toBe('trial_ending_24h');
    Queue::assertPushed(DeliverTelegramNotification::class, 1);

    Carbon::setTestNow(now()->addHours(21));
    app(TrialLifecycleService::class)->processDue();

    expect(NotificationDelivery::query()->where('user_id', $trial['user']->id)->pluck('type')->all())
        ->toContain('trial_ending_24h', 'trial_ending_3h');
    Queue::assertPushed(DeliverTelegramNotification::class, 2);
});

it('expires trial access, freezes active queries, and skips queued notifications', function () {
    Queue::fake();
    Carbon::setTestNow('2026-08-25 10:00:00');
    $trial = trialAccess(endsAt: now()->subMinute());
    $query = SearchQuery::query()->create([
        'user_id' => $trial['user']->id,
        'name' => 'Поддержка сайта',
        'keywords' => ['поддержка'],
        'status' => QueryStatus::Active,
        'monitoring_started_at' => now()->subDay(),
    ]);
    $delivery = NotificationDelivery::query()->create([
        'user_id' => $trial['user']->id,
        'type' => 'tender_card',
        'status' => NotificationStatus::Queued,
        'idempotency_key' => 'expired-trial-delivery',
        'scheduled_at' => now()->subMinute(),
    ]);

    app(TrialLifecycleService::class)->processDue();
    app(TrialLifecycleService::class)->processDue();

    expect($trial['entitlement']->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($trial['subscription']->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($query->fresh()->status)->toBe(QueryStatus::Frozen)
        ->and($delivery->fresh()->status)->toBe(NotificationStatus::Skipped)
        ->and($delivery->fresh()->failure_code)->toBe('access_expired');
    Queue::assertNothingPushed();
});

it('does not queue or deliver tender notifications after access expires', function () {
    Http::fake();
    Carbon::setTestNow('2026-08-25 10:00:00');
    $trial = trialAccess(endsAt: now()->subMinute());
    $query = SearchQuery::query()->create([
        'user_id' => $trial['user']->id,
        'name' => 'Поддержка сайта',
        'keywords' => ['поддержка'],
        'status' => QueryStatus::Active,
        'monitoring_started_at' => now()->subDay(),
    ]);
    $tender = Tender::query()->create([
        'source' => 'fixture',
        'external_id' => 'expired-access-tender',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/expired-access',
        'canonical_url_hash' => hash('sha256', 'expired-access-tender'),
        'title' => 'Поддержка сайта',
    ]);
    $match = TenderQueryMatch::query()->create([
        'tender_id' => $tender->id,
        'search_query_id' => $query->id,
        'match_reasons' => ['keywords' => 'matched'],
        'matched_at' => now(),
    ]);

    expect(app(NotificationService::class)->queueForMatch($match))->toBeNull();

    $delivery = NotificationDelivery::query()->create([
        'user_id' => $trial['user']->id,
        'type' => 'tender_card',
        'status' => NotificationStatus::Queued,
        'idempotency_key' => 'stale-delivery',
        'scheduled_at' => now(),
    ]);

    (new DeliverTelegramNotification($delivery->id))->handle(
        app(TelegramBotClient::class),
        app(AccessService::class),
    );

    expect($delivery->fresh()->status)->toBe(NotificationStatus::Skipped)
        ->and($delivery->fresh()->failure_code)->toBe('access_expired');
    Http::assertNothingSent();
});

/**
 * @return array{user: User, subscription: Subscription, entitlement: Entitlement}
 */
function trialAccess(Carbon $endsAt): array
{
    $user = User::factory()->create(['telegram_id' => (string) fake()->unique()->numberBetween(800000, 899999)]);
    $plan = Plan::query()->firstOrCreate(
        ['code' => 'basic'],
        ['name' => 'Базовый', 'is_active' => true, 'limits' => ['active_queries' => 3]],
    );
    $subscription = Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'source' => SubscriptionSource::Trial,
        'status' => SubscriptionStatus::Active,
        'starts_at' => $endsAt->copy()->subHours(72),
        'ends_at' => $endsAt,
    ]);
    $entitlement = Entitlement::query()->create([
        'user_id' => $user->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => $endsAt->copy()->subHours(72),
        'ends_at' => $endsAt,
    ]);

    return compact('user', 'subscription', 'entitlement');
}
