<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('opens the EIS workspace for a signed-in Telegram super admin', function () {
    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'telegram_id' => 'telegram-super-admin',
    ]);

    $this->actingAs($admin)
        ->get('/mvp/workspace')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MvpWorkspace')
            ->has('currentTenders')
            ->has('historyTenders'));
});

it('allows a Telegram super admin to start a manual EIS workspace search', function () {
    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
        'telegram_id' => 'telegram-super-admin-search',
    ]);

    $this->actingAs($admin)
        ->postJson('/local/mvp/eis-rss-preview', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('query');
});

it('does not expose the EIS workspace to subscribers', function () {
    $subscriber = User::factory()->create([
        'role' => UserRole::Subscriber,
        'telegram_id' => 'telegram-subscriber',
    ]);

    $this->actingAs($subscriber)->get('/mvp/workspace')->assertForbidden();
});
