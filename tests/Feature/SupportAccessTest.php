<?php

use App\Enums\AccessState;
use App\Enums\ConsentDocument;
use App\Enums\NotificationStatus;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\SearchQuery;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccessService;
use App\Services\ConsentService;
use App\Services\PlanCatalog;
use App\Services\SupportTicketService;
use App\Services\TrialLifecycleService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('tender.local_mvp_full_access.enabled', false);
    config()->set('tender.local_mvp_operator.enabled', false);
    config()->set('tender.legal.documents_published', true);
    config()->set('tender.legal.offer_url', 'https://example.test/offer');
    config()->set('tender.legal.offer_version', '2026-09-29');
    config()->set('tender.legal.privacy_url', 'https://example.test/privacy');
    config()->set('tender.legal.privacy_version', '2026-09-29');
});

it('grants bounded Basic access only to the ticket owner and writes an audit entry', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $owner = User::factory()->create();
    $ticket = app(SupportTicketService::class)->create($owner, 'access', 'Не могу работать с мониторингом.');
    app(ConsentService::class)->acceptCurrent($owner, [ConsentDocument::Offer, ConsentDocument::Privacy], null);

    $this->actingAs($owner)->post("/support/admin/{$ticket->id}/access", [
        'days' => 3, 'reason' => 'Компенсация за технический сбой.',
    ])->assertForbidden();
    expect(Entitlement::query()->count())->toBe(0);

    $this->actingAs($admin)->post("/support/admin/{$ticket->id}/access", [
        'days' => 8, 'reason' => 'Компенсация за технический сбой.',
    ])->assertSessionHasErrors('days');
    $this->post("/support/admin/{$ticket->id}/access", [
        'days' => 3, 'reason' => 'коротко',
    ])->assertSessionHasErrors('reason');
    expect(Entitlement::query()->count())->toBe(0);

    $this->post("/support/admin/{$ticket->id}/access", [
        'days' => 3, 'reason' => 'Компенсация за технический сбой.',
    ])->assertRedirect();
    $entitlement = Entitlement::query()->with('subscription')->firstOrFail();
    expect($entitlement->user_id)->toBe($owner->id)
        ->and($entitlement->subscription?->source)->toBe(SubscriptionSource::AdminGrant)
        ->and($entitlement->value)->toBe(3)
        ->and($entitlement->starts_at->diffInDays($entitlement->ends_at))->toBe(3.0)
        ->and($ticket->events()->where('action', 'access_granted')->count())->toBe(1);
    expect($ticket->events()->latest('id')->firstOrFail()->reason)->toBe('Компенсация за технический сбой.');

    $this->post("/support/admin/{$ticket->id}/access", [
        'days' => 3, 'reason' => 'Повторный запрос на ручной доступ.',
    ])->assertSessionHasErrors('days');
    expect(Entitlement::query()->count())->toBe(1);
    $this->get("/support/admin/{$ticket->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manualGrant.id', $entitlement->id)
            ->where('grantBlockReason', 'У пользователя уже есть активный доступ.')
            ->where('ticket.events.1.action', 'access_granted')
            ->missing('ticket.events.1.metadata'));
});

it('requires current legal consent and never changes an existing trial or paid entitlement', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $owner = User::factory()->create();
    $ticket = app(SupportTicketService::class)->create($owner, 'access', 'Нужна помощь с доступом.');

    $this->actingAs($admin)->post("/support/admin/{$ticket->id}/access", [
        'days' => 2, 'reason' => 'Проверка отсутствующих согласий.',
    ])->assertSessionHasErrors('days');
    config()->set('tender.legal.documents_published', false);
    $this->post("/support/admin/{$ticket->id}/access", [
        'days' => 2, 'reason' => 'Проверка неопубликованных документов.',
    ])->assertSessionHasErrors('days');
    config()->set('tender.legal.documents_published', true);
    app(ConsentService::class)->acceptCurrent($owner, [ConsentDocument::Offer, ConsentDocument::Privacy], null);

    $plan = app(PlanCatalog::class)->basic();
    $subscription = Subscription::query()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'source' => SubscriptionSource::TelegramStars,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(20),
    ]);
    $paid = Entitlement::query()->create([
        'user_id' => $owner->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(20),
    ]);
    $this->post("/support/admin/{$ticket->id}/access", [
        'days' => 2, 'reason' => 'Нельзя заменить оплаченный доступ.',
    ])->assertSessionHasErrors('days');
    $this->delete("/support/admin/{$ticket->id}/access", [
        'reason' => 'Нельзя отменять оплаченный доступ.', 'confirm' => true,
    ])->assertSessionHasErrors('reason');
    expect($paid->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($ticket->events()->whereIn('action', ['access_granted', 'access_revoked'])->count())->toBe(0);
});

it('revokes only the manual grant and freezes work when no access remains', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $owner = User::factory()->create();
    $ticket = app(SupportTicketService::class)->create($owner, 'access', 'Нужно исправить доступ.');
    app(ConsentService::class)->acceptCurrent($owner, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $this->actingAs($admin)->post("/support/admin/{$ticket->id}/access", [
        'days' => 2, 'reason' => 'Временное восстановление доступа.',
    ])->assertRedirect();
    $entitlement = Entitlement::query()->firstOrFail();
    $originalEnd = $entitlement->ends_at;
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Тестовый мониторинг',
        'keywords' => ['тест'],
        'status' => QueryStatus::Active,
    ]);
    $delivery = NotificationDelivery::query()->create([
        'user_id' => $owner->id,
        'type' => 'tender_card',
        'status' => NotificationStatus::Queued,
        'idempotency_key' => 'support-access-revoke',
        'payload' => [],
        'scheduled_at' => now(),
    ]);

    $this->delete("/support/admin/{$ticket->id}/access", [
        'reason' => 'Причина указана, но нет подтверждения.',
    ])->assertSessionHasErrors('confirm');
    expect($entitlement->fresh()->status)->toBe(SubscriptionStatus::Active);

    $this->delete("/support/admin/{$ticket->id}/access", [
        'reason' => 'Проверка завершена, доступ отзываем.', 'confirm' => true,
    ])->assertRedirect();
    expect($entitlement->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($entitlement->subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($query->fresh()->status)->toBe(QueryStatus::Frozen)
        ->and($delivery->fresh()->status)->toBe(NotificationStatus::Skipped);
    $event = $ticket->events()->latest('id')->firstOrFail();
    expect($event->action)->toBe('access_revoked')
        ->and($event->access_entitlement_id)->toBe($entitlement->id)
        ->and($event->access_ends_at?->equalTo($originalEnd))->toBeTrue()
        ->and($event->reason)->toBe('Проверка завершена, доступ отзываем.');
    $this->delete("/support/admin/{$ticket->id}/access", [
        'reason' => 'Повторный отзыв невозможен после отмены.', 'confirm' => true,
    ])->assertSessionHasErrors('reason');
});

it('expires a manual grant automatically without sending a trial reminder', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $owner = User::factory()->create();
    $ticket = app(SupportTicketService::class)->create($owner, 'access', 'Нужна временная помощь.');
    app(ConsentService::class)->acceptCurrent($owner, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $this->actingAs($admin)->post("/support/admin/{$ticket->id}/access", [
        'days' => 1, 'reason' => 'Временный доступ для проверки.',
    ])->assertRedirect();
    $entitlement = Entitlement::query()->firstOrFail();
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Проверка окончания',
        'keywords' => ['тест'],
        'status' => QueryStatus::Active,
    ]);
    $entitlement->forceFill(['ends_at' => now()->subMinute()])->save();
    $entitlement->subscription?->forceFill(['ends_at' => now()->subMinute()])->save();

    app(TrialLifecycleService::class)->processDue();
    expect($entitlement->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($entitlement->subscription?->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($query->fresh()->status)->toBe(QueryStatus::Frozen)
        ->and(app(AccessService::class)->snapshotFor($owner)->state)->toBe(AccessState::Preview)
        ->and(NotificationDelivery::query()->where('user_id', $owner->id)->count())->toBe(0);
});

it('keeps paid access and active work when a later payment overlaps a manual grant', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $owner = User::factory()->create();
    $ticket = app(SupportTicketService::class)->create($owner, 'access', 'Проверка двух источников доступа.');
    app(ConsentService::class)->acceptCurrent($owner, [ConsentDocument::Offer, ConsentDocument::Privacy], null);
    $this->actingAs($admin)->post("/support/admin/{$ticket->id}/access", [
        'days' => 1, 'reason' => 'Временная помощь до подтверждения оплаты.',
    ])->assertRedirect();
    $manual = Entitlement::query()->firstOrFail();
    $plan = app(PlanCatalog::class)->basic();
    $paidSubscription = Subscription::query()->create([
        'user_id' => $owner->id,
        'plan_id' => $plan->id,
        'source' => SubscriptionSource::TelegramStars,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now(),
        'ends_at' => now()->addDays(30),
    ]);
    $paid = Entitlement::query()->create([
        'user_id' => $owner->id,
        'subscription_id' => $paidSubscription->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(30),
    ]);
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Активный оплаченный мониторинг',
        'keywords' => ['тест'],
        'status' => QueryStatus::Active,
    ]);

    $this->delete("/support/admin/{$ticket->id}/access", [
        'reason' => 'Оплата подтверждена, ручной доступ не нужен.',
        'confirm' => true,
    ])->assertRedirect();
    expect($manual->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($paid->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($query->fresh()->status)->toBe(QueryStatus::Active);
});
