<?php

use App\Enums\ConsentDocument;
use App\Enums\NotificationStatus;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\DeliverTelegramNotification;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\SearchQuery;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AccessChangeNotificationService;
use App\Services\AccessService;
use App\Services\ConsentService;
use App\Services\PermanentProGrantService;
use App\Services\TelegramBotClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    config()->set('tender.local_mvp_full_access.enabled', false);
    config()->set('tender.local_mvp_operator.enabled', false);
    config()->set('tender.legal.documents_published', true);
    config()->set('tender.legal.offer_url', 'https://example.test/offer');
    config()->set('tender.legal.offer_version', '2026-09-29');
    config()->set('tender.legal.privacy_url', 'https://example.test/privacy');
    config()->set('tender.legal.privacy_version', '2026-09-29');
});

it('grants one audited permanent Pro developer access, restores work, and queues a personal notice', function () {
    $user = User::factory()->create([
        'telegram_id' => '415595220',
        'role' => UserRole::Subscriber,
    ]);
    app(ConsentService::class)->acceptCurrent($user, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $query = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Замороженный мониторинг',
        'keywords' => ['тест'],
        'status' => QueryStatus::Frozen,
        'frozen_at' => now(),
    ]);

    $code = Artisan::call('access:grant-permanent-pro', [
        'telegram-id' => '415595220',
        '--developer-mode' => true,
        '--reason' => 'Коллега разработчик проверяет пользовательские сценарии.',
    ]);

    expect($code)->toBe(0);
    $entitlement = Entitlement::query()->with(['plan', 'subscription'])->sole();
    expect($entitlement->plan?->code)->toBe('pro')
        ->and($entitlement->status)->toBe(SubscriptionStatus::Active)
        ->and($entitlement->ends_at)->toBeNull()
        ->and($entitlement->subscription?->ends_at)->toBeNull()
        ->and($entitlement->metadata['developer_mode'])->toBeTrue()
        ->and($query->fresh()->status)->toBe(QueryStatus::Active)
        ->and($user->fresh()->role)->toBe(UserRole::Subscriber)
        ->and(app(AccessService::class)->snapshotFor($user)->toArray()['mode'])->toBe('developer');
    $ticket = SupportTicket::query()->sole();
    expect($ticket->events()->where('action', 'access_granted')->count())->toBe(1)
        ->and($ticket->messages()->count())->toBe(1);
    $delivery = NotificationDelivery::query()->sole();
    expect($delivery->type)->toBe('access_granted')
        ->and($delivery->payload['developer_mode'])->toBeTrue();
    Queue::assertPushed(DeliverTelegramNotification::class, 1);

    expect(Artisan::call('access:grant-permanent-pro', [
        'telegram-id' => '415595220',
        '--developer-mode' => true,
        '--reason' => 'Повторное назначение того же доступа запрещено.',
    ]))->toBe(1);
    expect(Entitlement::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(1);
});

it('does not grant permanent Pro without current legal consent', function () {
    User::factory()->create(['telegram_id' => '415595220']);

    expect(Artisan::call('access:grant-permanent-pro', [
        'telegram-id' => '415595220',
        '--reason' => 'Проверка обязательных согласий пользователя.',
    ]))->toBe(1);
    expect(Entitlement::query()->count())->toBe(0)
        ->and(SupportTicket::query()->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

it('delivers account changes even after access ends and never sends one event twice', function () {
    config()->set('tender.telegram.bot_token', 'test-token');
    config()->set('tender.telegram.mini_app_url', 'https://example.test/app');
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $user = User::factory()->create(['telegram_id' => '415595220']);
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'instant_enabled' => false,
        'digest_enabled' => false,
        'digest_time' => '09:00',
        'timezone' => 'Europe/Moscow',
    ]);
    app(ConsentService::class)->acceptCurrent($user, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $entitlement = app(PermanentProGrantService::class)->grant(
        '415595220',
        true,
        'Коллега разработчик проверяет пользовательские сценарии.',
    );
    $grant = NotificationDelivery::query()->sole();
    $job = new DeliverTelegramNotification($grant->id);
    $job->handle(app(TelegramBotClient::class), app(AccessService::class));
    $job->handle(app(TelegramBotClient::class), app(AccessService::class));
    expect($grant->fresh()->status)->toBe(NotificationStatus::Sent);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains(
        (string) $request->data()['text'],
        'Про (Developer Mode) без даты окончания',
    ));

    $ticket = SupportTicket::query()->sole();
    $updateEvent = $ticket->events()->create([
        'action' => 'access_updated',
        'reason' => 'Проверка уведомления об обновлении доступа.',
        'access_entitlement_id' => $entitlement->id,
        'access_plan_code' => 'pro',
        'created_at' => now(),
    ]);
    $update = app(AccessChangeNotificationService::class)->updated($entitlement, $updateEvent->id);
    (new DeliverTelegramNotification($update->id))
        ->handle(app(TelegramBotClient::class), app(AccessService::class));
    expect($update->fresh()->status)->toBe(NotificationStatus::Sent);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains(
        (string) $request->data()['text'],
        'Администратор обновил вам доступ',
    ));

    $entitlement->forceFill(['status' => SubscriptionStatus::Cancelled, 'ends_at' => now()])->save();
    $entitlement->subscription?->forceFill(['status' => SubscriptionStatus::Cancelled, 'ends_at' => now()])->save();
    $event = $ticket->events()->create([
        'action' => 'access_revoked',
        'reason' => 'Проверка уведомления после отключения доступа.',
        'access_entitlement_id' => $entitlement->id,
        'access_plan_code' => 'pro',
        'created_at' => now(),
    ]);
    $revocation = app(AccessChangeNotificationService::class)->revoked($entitlement, $event->id);
    app(AccessChangeNotificationService::class)->revoked($entitlement, $event->id);
    expect(NotificationDelivery::query()->count())->toBe(3);
    (new DeliverTelegramNotification($revocation->id))
        ->handle(app(TelegramBotClient::class), app(AccessService::class));
    expect($revocation->fresh()->status)->toBe(NotificationStatus::Sent);
    Http::assertSentCount(3);
});

it('prefers permanent Pro when another shorter entitlement is active', function () {
    $user = User::factory()->create(['telegram_id' => '415595220']);
    app(ConsentService::class)->acceptCurrent($user, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $permanent = app(PermanentProGrantService::class)->grant(
        '415595220',
        true,
        'Коллега разработчик проверяет пользовательские сценарии.',
    );
    Entitlement::query()->create([
        'user_id' => $user->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => now(),
        'ends_at' => now()->addDay(),
    ]);

    expect(app(AccessService::class)->snapshotFor($user)->planCode)->toBe('pro')
        ->and(app(AccessService::class)->snapshotFor($user)->activeQueryLimit)->toBe(10)
        ->and($permanent->ends_at)->toBeNull();
});
