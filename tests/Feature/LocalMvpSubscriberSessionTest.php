<?php

use App\Enums\AccessState;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AccessService;

beforeEach(function (): void {
    config()->set('tender.local_mvp_subscriber.enabled', true);
});

it('provides a local-only subscriber identity without accepting a browser Telegram id', function () {
    $this->get('/local/mvp-subscriber')
        ->assertRedirect(route('onboarding'));

    $subscriber = User::query()
        ->where('email', 'local-mvp-subscriber@tenderfinder.invalid')
        ->firstOrFail();

    $this->assertAuthenticatedAs($subscriber);
    expect($subscriber->role)->toBe(UserRole::Subscriber)
        ->and($subscriber->telegram_id)->toBeNull()
        ->and(app(AccessService::class)->snapshotFor($subscriber)->state)
        ->toBe(AccessState::Preview);
});

it('does not expose the local subscriber entry outside its explicit flag', function () {
    config()->set('tender.local_mvp_subscriber.enabled', false);

    $this->get('/local/mvp-subscriber')->assertNotFound();
});
