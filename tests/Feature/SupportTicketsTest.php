<?php

use App\Enums\NotificationStatus;
use App\Enums\QueryStatus;
use App\Enums\UserRole;
use App\Models\NotificationDelivery;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use Inertia\Testing\AssertableInertia as Assert;

it('lets a subscriber create and discuss only their own support ticket', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)->post('/support', [
        'category' => 'monitoring',
        'body' => 'Последняя проверка не показывает результат с утра.',
    ])->assertRedirect();
    $ticket = SupportTicket::query()->firstOrFail();

    $this->actingAs($owner)->get('/support')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Support')
            ->has('tickets', 1)
            ->where('tickets.0.id', $ticket->id));
    $this->get("/support/{$ticket->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('SupportTicket')
            ->has('ticket.messages', 1)
            ->missing('ticket.events'));

    $this->actingAs($other)->get("/support/{$ticket->id}")->assertNotFound();
    $this->post("/support/{$ticket->id}/reply", ['body' => 'Чужой ответ'])->assertNotFound();
    $this->get('/support/admin')->assertForbidden();
    $this->get("/support/admin/{$ticket->id}")->assertForbidden();

    $this->actingAs($owner)->post("/support/{$ticket->id}/reply", ['body' => 'Есть уточнение.'])
        ->assertRedirect();
    expect($ticket->messages()->count())->toBe(2);
    expect($ticket->events()->count())->toBe(2);

    $ticket->update(['status' => 'resolved']);
    $this->post("/support/{$ticket->id}/reply", ['body' => 'Проблема повторилась.'])
        ->assertRedirect();
    expect($ticket->fresh()->status)->toBe('open');
    expect($ticket->events()->latest('id')->firstOrFail()->old_status)->toBe('resolved');
});

it('requires bounded support messages and never creates an invalid ticket', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/support', [
        'category' => 'billing',
        'body' => 'Коротко',
    ])->assertSessionHasErrors(['category', 'body']);

    expect(SupportTicket::query()->count())->toBe(0);
});

it('shows scoped safe diagnostics and records every support workflow change', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $subscriber = User::factory()->create();
    $ticket = app(SupportTicketService::class)->create($owner, 'notifications', 'Сообщения перестали приходить после обеда.');
    $this->actingAs($owner)->get('/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('openSupportTickets', null));
    $this->actingAs($admin)->get('/profile')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('openSupportTickets', 1));
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Секретная поисковая фраза',
        'keywords' => ['секретное слово'],
        'status' => QueryStatus::Active,
    ]);
    $feed = SourceFeed::query()->create([
        'source' => 'rostender',
        'source_identifier' => 123,
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/123',
        'url_hash' => hash('sha256', 'support-test-feed'),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
    ]);
    RostenderFeedSearchQuery::query()->create([
        'source_feed_id' => $feed->id,
        'search_query_id' => $query->id,
    ]);
    NotificationDelivery::query()->create([
        'user_id' => $owner->id,
        'type' => 'tender_card',
        'status' => NotificationStatus::Failed,
        'idempotency_key' => 'support-safe-delivery',
        'payload' => ['title' => 'Секретная карточка'],
        'failure_code' => 'telegram_delivery_failed',
        'scheduled_at' => now(),
    ]);

    $this->actingAs($admin)->get("/support/admin/{$ticket->id}")
        ->assertOk()
        ->assertDontSee('Секретная поисковая фраза')
        ->assertDontSee('секретное слово')
        ->assertDontSee('Секретная карточка')
        ->assertInertia(fn (Assert $page) => $page
            ->component('AdminSupportTicket')
            ->where('ticket.user_id', $owner->id)
            ->where('diagnostics.monitorings.0.id', $query->id)
            ->where('diagnostics.monitorings.0.sources.0.state', 'pending')
            ->where('diagnostics.deliveries.0.status', 'failed')
            ->missing('diagnostics.searchQueries')
            ->missing('diagnostics.payload'));

    $this->patch("/support/admin/{$ticket->id}", [
        'status' => 'in_progress',
        'assignee_id' => $subscriber->id,
        'reason' => 'Назначаю для разбора обращения.',
    ])->assertSessionHasErrors('assignee_id');
    $this->patch("/support/admin/{$ticket->id}", [
        'status' => 'in_progress',
        'assignee_id' => $admin->id,
        'reason' => 'Коротко',
    ])->assertSessionHasErrors('reason');
    expect($ticket->fresh()->status)->toBe('open');

    $this->patch("/support/admin/{$ticket->id}", [
        'status' => 'in_progress',
        'assignee_id' => $admin->id,
        'reason' => 'Проверяю доставку и отвечу пользователю.',
    ])->assertRedirect();
    expect($ticket->fresh()->status)->toBe('in_progress');
    expect($ticket->events()->where('action', 'workflow_changed')->count())->toBe(1);
    expect($ticket->events()->latest('id')->firstOrFail()->reason)
        ->toBe('Проверяю доставку и отвечу пользователю.');

    $this->post("/support/admin/{$ticket->id}/reply", ['body' => 'Проверяем доставку.'])
        ->assertRedirect();
    $this->actingAs($owner)->get("/support/{$ticket->id}")
        ->assertOk()
        ->assertDontSee('Проверяю доставку и отвечу пользователю.')
        ->assertInertia(fn (Assert $page) => $page
            ->where('ticket.messages.1.body', 'Проверяем доставку.')
            ->missing('ticket.events'));
});
