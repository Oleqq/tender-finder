<?php

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
