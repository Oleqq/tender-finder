<?php

use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;
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

    $user = User::factory()->create();
    $query = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Разработка сайтов',
        'keywords' => ['разработка', 'сайт'],
        'status' => 'active',
        'monitoring_started_at' => now()->subHour(),
    ]);
    LocalMvpSearchSnapshot::query()->create([
        'user_id' => $user->id,
        'search_query_id' => $query->id,
        'query' => 'разработка сайт',
        'source' => 'eis_rss',
        'tender_ids' => [],
        'items_seen' => 14,
        'items_matched' => 3,
        'items_created' => 2,
        'pages_requested' => 3,
        'pages_loaded' => 2,
        'partially_loaded' => true,
    ]);

    $this->actingAs($user)
        ->get('/queries')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('MyQueries')
            ->where('queries.0.id', $query->id)
            ->where('queries.0.last_run.items_seen', 14)
            ->where('queries.0.last_run.items_matched', 3)
            ->where('queries.0.last_run.items_created', 2)
            ->where('queries.0.last_run.pages_requested', 3)
            ->where('queries.0.last_run.pages_loaded', 2)
            ->where('queries.0.last_run.partially_loaded', true));
});
