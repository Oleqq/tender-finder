<?php

use App\Models\NotificationPreference;
use App\Models\SearchQuery;
use App\Models\TenderFeedView;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('saves applies and deletes a user scoped tender feed view', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $query = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Сайты',
        'keywords' => ['сайт'],
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->postJson('/tender-feed-views', [
        'name' => 'Срочные избранные',
        'filters' => [
            'q' => 'портал',
            'status' => 'favorite',
            'query_id' => $query->id,
            'sort' => 'deadline_asc',
            'unknown' => 'drop-me',
        ],
    ])->assertCreated()
        ->assertJsonPath('view.name', 'Срочные избранные')
        ->assertJsonMissingPath('view.filters.unknown');

    $viewId = $response->json('view.id');
    $this->actingAs($user)->get('/tenders')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenders')
            ->where('savedViews.0.id', $viewId)
            ->where('savedViews.0.filters.status', 'favorite'));

    $this->actingAs($other)->deleteJson('/tender-feed-views/'.$viewId)->assertNotFound();
    $this->actingAs($user)->deleteJson('/tender-feed-views/'.$viewId)->assertNoContent();
    expect(TenderFeedView::query()->find($viewId))->toBeNull();
});

it('persists notification preferences and renders the local preview inputs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/profile/notification-preferences', [
        'instant_enabled' => false,
        'digest_enabled' => true,
        'digest_time' => '08:30',
        'timezone' => 'Asia/Yekaterinburg',
    ])->assertOk()
        ->assertJsonPath('preferences.instant_enabled', false)
        ->assertJsonPath('preferences.digest_time', '08:30');

    expect(NotificationPreference::query()->where('user_id', $user->id)->first())
        ->instant_enabled->toBeFalse()
        ->digest_enabled->toBeTrue()
        ->timezone->toBe('Asia/Yekaterinburg');

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('notificationPreferences.instant_enabled', false)
            ->where('notificationPreferences.digest_enabled', true)
            ->where('notificationPreferences.digest_time', '08:30')
            ->where('notificationPreferences.timezone', 'Asia/Yekaterinburg'));
});

it('rejects another users monitoring in a saved feed view', function () {
    $user = User::factory()->create();
    $foreignQuery = SearchQuery::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Чужой',
        'keywords' => ['чужой'],
        'status' => 'active',
    ]);

    $this->actingAs($user)->postJson('/tender-feed-views', [
        'name' => 'Недоступный вид',
        'filters' => ['query_id' => $foreignQuery->id],
    ])->assertUnprocessable();
});
