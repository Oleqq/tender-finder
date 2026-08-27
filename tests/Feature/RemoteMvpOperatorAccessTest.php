<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
});

it('keeps remote MVP operator access unavailable until the explicit production setting is enabled', function () {
    config()->set('tender.remote_mvp_operator.enabled', false);

    $url = URL::temporarySignedRoute('mvp.remote-operator.session', now()->addMinutes(10));

    $this->get($url)->assertNotFound();
    $this->assertGuest();
});

it('opens a remote MVP workspace only through a valid expiring signed link', function () {
    config()->set('tender.remote_mvp_operator.enabled', true);

    $url = URL::temporarySignedRoute('mvp.remote-operator.session', now()->addMinutes(10));

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MvpWorkspace')
            ->has('currentTenders')
            ->has('historyTenders'));

    $operator = User::query()->where('email', 'local-mvp-operator@tenderfinder.invalid')->firstOrFail();
    $this->assertAuthenticatedAs($operator);
    expect($operator->role)->toBe(UserRole::SuperAdmin);
});

it('rejects an unsigned remote MVP operator request', function () {
    config()->set('tender.remote_mvp_operator.enabled', true);

    $this->get('/mvp/operator/access')->assertForbidden();
    $this->assertGuest();
});

it('revokes an existing technical MVP session when remote access is disabled', function () {
    config()->set('tender.remote_mvp_operator.enabled', true);
    $url = URL::temporarySignedRoute('mvp.remote-operator.session', now()->addMinutes(10));

    $this->get($url)->assertOk();
    config()->set('tender.remote_mvp_operator.enabled', false);

    $this->get('/operations-demo')->assertForbidden();
});

it('creates a remote test link only when remote MVP access is enabled', function () {
    config()->set('tender.remote_mvp_operator.enabled', false);

    $this->artisan('mvp:operator-link')
        ->expectsOutputToContain('Remote MVP operator access is disabled')
        ->assertExitCode(1);

    config()->set('tender.remote_mvp_operator.enabled', true);

    $this->artisan('mvp:operator-link --minutes=5')
        ->expectsOutputToContain('/mvp/operator/access?')
        ->assertExitCode(0);
});
