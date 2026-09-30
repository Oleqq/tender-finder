<?php

use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

it('shows only the signed-in users nearest actions and summary', function () {
    Carbon::setTestNow('2026-09-03 12:00:00');

    $owner = User::factory()->create(['telegram_id' => '9301']);
    $other = User::factory()->create(['telegram_id' => '9302']);
    $overdue = dashboardTender('dashboard-overdue', 'Просроченная закупка');
    $today = dashboardTender('dashboard-today', 'Закупка на сегодня');
    $later = dashboardTender('dashboard-later', 'Следующая закупка');
    $foreign = dashboardTender('dashboard-foreign', 'Чужое действие');

    foreach ([
        [$owner, $overdue, '2026-09-02'],
        [$owner, $today, '2026-09-03'],
        [$owner, $later, '2026-09-05'],
        [$other, $foreign, '2026-09-01'],
    ] as [$user, $tender, $date]) {
        TenderUserState::query()->create([
            'user_id' => $user->id,
            'tender_id' => $tender->id,
            'status' => 'potential',
            'tags' => ['проверить'],
            'next_action_on' => $date,
        ]);
    }

    $this->actingAs($owner)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('nextActions.overdue_count', 1)
            ->where('nextActions.today_count', 1)
            ->has('nextActions.items', 3)
            ->where('nextActions.items.0.title', 'Просроченная закупка')
            ->where('nextActions.items.1.title', 'Закупка на сегодня')
            ->where('nextActions.items.2.title', 'Следующая закупка'));
});

it('points the signed-in user to the next useful step without leaking other users work', function () {
    $owner = User::factory()->create(['telegram_id' => '9311']);
    $other = User::factory()->create(['telegram_id' => '9312']);
    $foreignQuery = SearchQuery::query()->create([
        'user_id' => $other->id,
        'name' => 'Чужой поиск',
        'keywords' => ['строительство'],
        'status' => 'active',
    ]);
    TenderQueryMatch::query()->create([
        'tender_id' => dashboardTender('dashboard-foreign-match', 'Чужая закупка')->id,
        'search_query_id' => $foreignQuery->id,
        'match_reasons' => ['keywords' => ['строительство']],
        'matched_at' => now(),
    ]);

    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('workspace.hasMonitoring', false)
        ->where('workspace.hasMatches', false));

    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Свой поиск',
        'keywords' => ['сайт'],
        'status' => 'active',
    ]);
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('workspace.hasMonitoring', true)
        ->where('workspace.hasMatches', false));

    TenderQueryMatch::query()->create([
        'tender_id' => dashboardTender('dashboard-owner-match', 'Свой тендер')->id,
        'search_query_id' => $query->id,
        'match_reasons' => ['keywords' => ['сайт']],
        'matched_at' => now(),
    ]);
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('workspace.hasMonitoring', true)
        ->where('workspace.hasMatches', true));

    $query->update(['status' => 'deleted']);
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('workspace.hasMonitoring', false)
        ->where('workspace.hasMatches', true));
});

function dashboardTender(string $externalId, string $title): Tender
{
    return Tender::query()->create([
        'source' => 'fixture',
        'external_id' => $externalId,
        'canonical_url' => 'https://source.example.test/tenders/'.$externalId,
        'canonical_url_hash' => hash('sha256', $externalId),
        'title' => $title,
    ]);
}
