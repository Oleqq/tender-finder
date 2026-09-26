<?php

use App\Models\CalendarSubscription;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function calendarSubscriptionFixture(): array
{
    $user = User::factory()->create();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Календарь', 'keywords' => ['сервер'], 'status' => 'active']);
    $tender = Tender::query()->create([
        'source' => 'rostender', 'external_id' => 'calendar-subscription-101',
        'canonical_url' => 'https://rostender.info/tender/calendar-subscription-101',
        'canonical_url_hash' => hash('sha256', 'calendar-subscription-101'),
        'title' => 'Секретная заявка', 'deadline_at' => '2026-10-10 12:00:00',
    ]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);

    return [$user, $tender];
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 27)->setTime(10, 0));
});

it('creates rotates and revokes a private updating calendar link', function () {
    [$user] = calendarSubscriptionFixture();
    $first = $this->actingAs($user)->postJson('/calendar/subscription')->assertCreated()
        ->assertJsonPath('subscription.active', true)->json('subscription.url');
    expect(CalendarSubscription::query()->sole()->token_hash)->not->toContain(basename($first));
    $this->get($first)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('X-PUBLISHED-TTL:PT1H', false)->assertSee('Секретная заявка', false);

    $second = $this->postJson('/calendar/subscription')->assertCreated()->json('subscription.url');
    expect($second)->not->toBe($first);
    $this->get($first)->assertNotFound();
    $this->get($second)->assertOk();
    $this->deleteJson('/calendar/subscription')->assertOk()->assertJsonPath('subscription.active', false);
    $this->get($second)->assertNotFound();
});

it('stops a team feed when its owner is no longer a team member', function () {
    [$owner, $tender] = calendarSubscriptionFixture();
    $member = User::factory()->create();
    $team = Team::query()->create(['name' => 'Тендерный отдел', 'owner_id' => $owner->id]);
    DB::table('team_members')->insert([
        ['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner', 'created_at' => now()],
        ['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now()],
    ]);
    $participation = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id]);
    $participation->items()->create(['title' => 'Подготовить пакет', 'due_on' => '2026-10-05']);
    $url = $this->actingAs($member)->postJson('/calendar/subscription?team_id='.$team->id)
        ->assertCreated()->json('subscription.url');
    $this->get($url)->assertOk()->assertSee('Подготовить пакет', false);

    $team->update(['archived_at' => now()]);
    $this->get($url)->assertNotFound();
    $team->update(['archived_at' => null]);
    $this->get($url)->assertOk();
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->delete();
    $this->get($url)->assertNotFound();
});

it('does not expose subscription management without authentication', function () {
    $this->postJson('/calendar/subscription')->assertUnauthorized();
    $this->deleteJson('/calendar/subscription')->assertUnauthorized();
    $this->get('/calendar/subscriptions/'.str_repeat('a', 64).'.ics')->assertNotFound();
});
