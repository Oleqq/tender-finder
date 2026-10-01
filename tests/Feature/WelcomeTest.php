<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the Tender Finder start page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

it('opens the right first screen for each authenticated access state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertRedirect('/onboarding');

    $user->update(['trial_used_at' => now()->subDay()]);
    $this->actingAs($user)->get('/')->assertRedirect('/dashboard');

    config()->set('tender.local_mvp_full_access.enabled', true);
    $this->actingAs($user)->get('/')->assertRedirect('/tenders');
});
