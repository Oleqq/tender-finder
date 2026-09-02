<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

dataset('mini app pages', [
    'onboarding' => ['/onboarding', 'Onboarding'],
]);

it('renders each Mini App screen as an Inertia response', function (string $uri, string $component) {
    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with('mini app pages');

it('keeps the consent screen behind the authenticated Telegram session', function () {
    $this->get('/consents')->assertRedirect('/onboarding');

    $this->actingAs(User::factory()->create())
        ->get('/consents')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Consents'));
});

it('keeps the user workspace behind an authenticated server session', function () {
    foreach (['/dashboard', '/tenders', '/profile', '/plans'] as $uri) {
        $this->get($uri)->assertRedirect('/onboarding');
    }

    $user = User::factory()->create();

    foreach ([
        '/dashboard' => 'Dashboard',
        '/tenders' => 'Tenders',
        '/profile' => 'Profile',
        '/plans' => 'Plans',
    ] as $uri => $component) {
        $this->actingAs($user)
            ->get($uri)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }
});

it('keeps saved monitors behind an authenticated Inertia route', function () {
    $this->get('/queries')->assertRedirect('/onboarding');

    $this->actingAs(User::factory()->create())
        ->get('/queries')
        ->assertOk()
        ->assertSee('MyQueries', false);
});
