<?php

use App\Enums\AccessState;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AccessService;

beforeEach(function (): void {
    config()->set('tender.local_mvp_subscriber.enabled', true);
    config()->set('tender.local_mvp_full_access.enabled', true);
    config()->set('tender.local_mvp_full_access.active_query_limit', 20);
});

it('provides local full access without accepting a browser Telegram id', function () {
    $this->get('/onboarding')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding')
            ->where('localSubscriberEntryEnabled', true));

    $this->get('/local/mvp-subscriber')
        ->assertRedirect(route('dashboard'));

    $subscriber = User::query()
        ->where('email', 'local-mvp-subscriber@tenderfinder.invalid')
        ->firstOrFail();

    $this->assertAuthenticatedAs($subscriber);
    expect($subscriber->role)->toBe(UserRole::SuperAdmin)
        ->and($subscriber->telegram_id)->toBeNull()
        ->and(app(AccessService::class)->snapshotFor($subscriber)->state)
        ->toBe(AccessState::Active)
        ->and(app(AccessService::class)->snapshotFor($subscriber)->planCode)
        ->toBe('local_mvp_full_access')
        ->and(app(AccessService::class)->snapshotFor($subscriber)->activeQueryLimit)
        ->toBe(20);

    $this->get('/operations')->assertOk();
});

it('returns the local identity to the subscriber preview path when full access is disabled', function () {
    config()->set('tender.local_mvp_full_access.enabled', false);

    $this->get('/local/mvp-subscriber')
        ->assertRedirect(route('onboarding'));

    $subscriber = User::query()
        ->where('email', 'local-mvp-subscriber@tenderfinder.invalid')
        ->firstOrFail();

    expect($subscriber->role)->toBe(UserRole::Subscriber)
        ->and(app(AccessService::class)->snapshotFor($subscriber)->state)
        ->toBe(AccessState::Preview);
});

it('does not expose the local subscriber entry outside its explicit flag', function () {
    config()->set('tender.local_mvp_subscriber.enabled', false);

    $this->get('/onboarding')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Onboarding')
            ->where('localSubscriberEntryEnabled', false));

    $this->get('/local/mvp-subscriber')->assertNotFound();
});
