<?php

use App\Enums\NotificationStatus;
use App\Enums\QueryStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Jobs\PollEisRssFeed;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;
use App\Models\Team;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TelegramBotClient;
use App\Services\TenderSourceImportService;
use App\Tenders\SourceFetchResult;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 18)->setTime(10, 0));
});

function monitoredQuery(User $user, QueryStatus $status = QueryStatus::Active): SearchQuery
{
    return SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Поставка серверов',
        'keywords' => ['сервер'],
        'status' => $status,
        'monitoring_started_at' => now()->subMinute(),
    ]);
}

function monitoredFeed(): SourceFeed
{
    return SourceFeed::query()->create([
        'source' => 'eis_rss',
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/results.html?searchString=test',
        'url_hash' => hash('sha256', 'monitoring-status-feed'),
        'status' => 'active',
        'poll_interval_seconds' => 600,
        'next_poll_at' => now()->addMinutes(10),
    ]);
}

it('shows a recovered source separately from its last failure', function () {
    $user = User::factory()->create();
    $query = monitoredQuery($user);
    $feed = monitoredFeed();
    SourceFeedSearchQuery::query()->create(['source_feed_id' => $feed->id, 'search_query_id' => $query->id]);
    app(TenderSourceImportService::class)->fail($feed, 'connection_failed', 'eis_rss');
    $this->travel(1)->minute();
    app(TenderSourceImportService::class)->import($feed->fresh(), new SourceFetchResult([], 0), 'eis_rss', false);

    $this->actingAs($user)->get('/queries')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('MyQueries')
        ->where('queries.0.source_statuses.0.source', 'eis_rss')
        ->where('queries.0.source_statuses.0.state', 'empty')
        ->where('queries.0.source_statuses.0.last_failure_at', now()->subMinute()->toAtomString())
        ->where('queries.0.source_statuses.0.last_success_items_seen', 0));
});

it('keeps a queued source distinct from an unavailable source and stops schedule when paused', function () {
    $user = User::factory()->create();
    $query = monitoredQuery($user);
    $feed = monitoredFeed();
    SourceFeedSearchQuery::query()->create(['source_feed_id' => $feed->id, 'search_query_id' => $query->id]);
    $feed->update(['last_attempt_at' => now()]);

    $this->actingAs($user)->get('/queries')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('queries.0.source_statuses.0.state', 'queued'));

    $query->update(['status' => QueryStatus::Paused]);
    $this->get('/queries')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('queries.0.source_statuses.0.state', 'paused')
        ->where('queries.0.source_statuses.0.next_attempt_at', null));
});

it('shows only the current users delivery statuses without payloads or foreign archived-team data', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    NotificationDelivery::query()->create([
        'user_id' => $user->id,
        'type' => 'tender_card',
        'status' => NotificationStatus::Failed,
        'idempotency_key' => 'monitoring-status-failed',
        'payload' => ['title' => 'Не показывать', 'url' => 'https://secret.example.test'],
        'scheduled_at' => now()->subMinute(),
        'failed_at' => now(),
        'failure_code' => 'telegram_delivery_failed',
    ]);
    NotificationDelivery::query()->create([
        'user_id' => $other->id,
        'type' => 'team_review_digest',
        'status' => NotificationStatus::Queued,
        'idempotency_key' => 'monitoring-status-foreign-archived',
        'payload' => ['team_name' => 'Архивная команда', 'title' => 'Чужие данные'],
        'scheduled_at' => now(),
    ]);

    $this->actingAs($user)->get('/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Profile')
        ->has('notificationDeliveries', 1)
        ->where('notificationDeliveries.0.type', 'Новое совпадение')
        ->where('notificationDeliveries.0.status', 'failed')
        ->where('notificationDeliveries.0.message', 'Не удалось доставить уведомление. Следующие уведомления будут отправляться автоматически.')
        ->missing('notificationDeliveries.0.payload')
        ->missing('notificationDeliveries.0.failure_code'));
});

it('marks an archived teams queued delivery as skipped and records an exhausted source job as unavailable', function () {
    $user = User::factory()->create(['telegram_id' => 'monitoring-status-user']);
    Entitlement::query()->create(['user_id' => $user->id, 'code' => 'active_queries', 'status' => 'active',
        'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $team = Team::query()->create(['owner_id' => $user->id, 'name' => 'Архивная команда', 'archived_at' => now()]);
    $delivery = NotificationDelivery::query()->create([
        'user_id' => $user->id,
        'type' => 'team_review_digest',
        'status' => NotificationStatus::Queued,
        'idempotency_key' => 'monitoring-status-archived-team',
        'payload' => ['team_id' => $team->id],
        'scheduled_at' => now(),
    ]);
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldNotReceive('sendMessage');
    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));

    $feed = monitoredFeed();
    (new PollEisRssFeed($feed->id))->failed(new RuntimeException('queue worker stopped'));

    expect($delivery->fresh()->status)->toBe(NotificationStatus::Skipped)
        ->and($feed->fresh()->last_error_code)->toBe('poll_job_failed');
});

it('records a Telegram transport error without exposing the transport failure to the profile', function () {
    $user = User::factory()->create(['telegram_id' => 'monitoring-status-telegram']);
    Entitlement::query()->create(['user_id' => $user->id, 'code' => 'active_queries', 'status' => 'active',
        'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $delivery = NotificationDelivery::query()->create([
        'user_id' => $user->id,
        'type' => 'tender_card',
        'status' => NotificationStatus::Queued,
        'idempotency_key' => 'monitoring-status-telegram-error',
        'payload' => ['title' => 'Тестовая закупка', 'url' => 'https://example.test/tender'],
        'scheduled_at' => now(),
    ]);
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldReceive('sendMessage')->once()->andThrow(new RuntimeException('transport detail'));

    expect(fn () => (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class)))
        ->toThrow(RuntimeException::class);
    expect($delivery->fresh()->status)->toBe(NotificationStatus::Failed)
        ->and($delivery->fresh()->failure_code)->toBe('telegram_delivery_failed');

    $this->actingAs($user)->get('/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('notificationDeliveries.0.status', 'failed')
        ->missing('notificationDeliveries.0.failure_code'));
});
