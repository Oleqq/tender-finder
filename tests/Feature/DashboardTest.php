<?php

use App\Models\Tender;
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

function dashboardTender(string $externalId, string $title): Tender
{
    return Tender::query()->create([
        'source' => 'fixture',
        'external_id' => $externalId,
        'canonical_url' => 'https://zakupki.gov.ru/'.$externalId,
        'canonical_url_hash' => hash('sha256', $externalId),
        'title' => $title,
    ]);
}
