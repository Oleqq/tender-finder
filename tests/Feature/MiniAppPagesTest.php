<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

dataset('mini app pages', [
    'onboarding' => ['/onboarding', 'Onboarding'],
    'consents' => ['/consents', 'Consents'],
    'dashboard' => ['/dashboard', 'Dashboard'],
    'tenders' => ['/tenders', 'Tenders'],
    'profile' => ['/profile', 'Profile'],
    'plans' => ['/plans', 'Plans'],
    'operations demo' => ['/operations-demo', 'OperationsDemo'],
]);

it('renders each Mini App screen as an Inertia response', function (string $uri, string $component) {
    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with('mini app pages');

it('keeps saved monitors behind an authenticated Inertia route', function () {
    $this->get('/queries')->assertRedirect('/onboarding');

    $this->actingAs(User::factory()->create())
        ->get('/queries')
        ->assertOk()
        ->assertSee('MyQueries', false);
});
