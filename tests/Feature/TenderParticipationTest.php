<?php

use App\Enums\UserRole;
use App\Models\LocalMvpSearchSnapshot;
use App\Models\NotificationPreference;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\TenderCalendarService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 9, 12)->setTime(10, 0));
});

function participationFixture(): array
{
    $user = User::factory()->create();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Серверы', 'keywords' => ['сервер'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'rostender', 'external_id' => 'work-101',
        'canonical_url' => 'https://rostender.info/tender/101', 'canonical_url_hash' => hash('sha256', 'work-101'),
        'title' => 'Поставка серверов', 'deadline_at' => '2026-09-30 22:30:00']);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);

    return [$user, $query, $tender];
}

it('starts participation only on explicit save and records transitions including reopening', function () {
    [$user, $query, $tender] = participationFixture();
    $url = '/tenders/'.$tender->id;
    $this->actingAs($user)->get($url.'/work')->assertOk()->assertInertia(fn (Assert $page) => $page->component('TenderWork')->where('participation', null));
    expect(TenderParticipation::query()->count())->toBe(0);
    $this->putJson($url.'/participation', ['stage' => 'studying', 'version' => 0])
        ->assertOk()->assertJsonPath('participation.version', 1)->assertJsonCount(1, 'participation.history');
    $this->putJson($url.'/participation', ['stage' => 'studying', 'version' => 1])
        ->assertOk()->assertJsonPath('participation.version', 1)->assertJsonCount(1, 'participation.history');
    $this->putJson($url.'/participation', ['stage' => 'lost', 'version' => 1])->assertUnprocessable();
    $this->putJson($url.'/participation', ['stage' => 'lost', 'loss_reason' => 'Конкурент дешевле', 'version' => 1])
        ->assertOk()->assertJsonPath('participation.loss_reason', 'Конкурент дешевле');
    $this->putJson($url.'/participation', ['stage' => 'preparing', 'version' => 2])
        ->assertOk()->assertJsonPath('participation.loss_reason', null)->assertJsonCount(3, 'participation.history')
        ->assertJsonPath('participation.history.1.reason', 'Конкурент дешевле');
    $this->putJson($url.'/participation', ['stage' => 'won', 'version' => 1])->assertConflict();
    $this->putJson($url.'/participation', ['stage' => 'invented', 'version' => 3])->assertUnprocessable();
    expect(DB::table('tender_participation_events')->count())->toBe(3);
});

it('supports task editing completion reopening deletion and optimistic concurrency', function () {
    [$user, $query, $tender] = participationFixture();
    $url = '/tenders/'.$tender->id;
    $this->actingAs($user)->postJson($url.'/checklist', ['title' => 'Не создаём участие автоматически'])->assertNotFound();
    $this->putJson($url.'/participation', ['stage' => 'preparing', 'version' => 0])->assertOk();
    $response = $this->postJson($url.'/checklist', ['title' => 'Документы', 'due_on' => '2026-09-15'])->assertCreated();
    $id = $response->json('participation.items.0.id');
    $data = ['title' => 'Проверить документы', 'due_on' => null, 'completed' => true, 'version' => 1];
    $this->patchJson($url.'/checklist/'.$id, $data)->assertOk()->assertJsonPath('participation.items.0.completed', true)
        ->assertJsonPath('participation.items.0.due_on', null)->assertJsonPath('participation.items.0.version', 2);
    $this->patchJson($url.'/checklist/'.$id, $data)->assertConflict();
    $this->patchJson($url.'/checklist/'.$id, [...$data, 'completed' => false, 'version' => 2])->assertOk()->assertJsonPath('participation.items.0.completed', false);
    $this->deleteJson($url.'/checklist/'.$id, ['version' => 1])->assertConflict();
    $this->deleteJson($url.'/checklist/'.$id, ['version' => 3])->assertOk()->assertJsonCount(0, 'participation.items');
    $this->postJson($url.'/checklist', ['title' => '   '])->assertUnprocessable();
    $this->postJson($url.'/checklist', ['title' => 'Тест', 'due_on' => '2026-02-30'])->assertUnprocessable();
});

it('keeps workflows private even when users see the same tender', function () {
    [$user, $query, $tender] = participationFixture();
    $url = '/tenders/'.$tender->id;
    $this->actingAs($user)->putJson($url.'/participation', ['stage' => 'lost', 'loss_reason' => 'Личная причина', 'version' => 0])->assertOk();
    $id = $this->postJson($url.'/checklist', ['title' => 'Секретная задача'])->json('participation.items.0.id');
    $other = User::factory()->create();
    $this->actingAs($other)->get($url.'/work')->assertNotFound();
    $this->putJson($url.'/participation', ['stage' => 'won', 'version' => 0])->assertNotFound();
    $otherQuery = SearchQuery::query()->create(['user_id' => $other->id, 'name' => 'Другой мониторинг', 'keywords' => ['сервер'], 'status' => 'active']);
    TenderQueryMatch::query()->create(['search_query_id' => $otherQuery->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);
    $this->get($url.'/work')->assertOk()->assertInertia(fn (Assert $page) => $page->where('participation', null));
    $this->deleteJson($url.'/checklist/'.$id, ['version' => 1])->assertNotFound();
    $this->patchJson($url.'/checklist/'.$id, ['title' => 'Чужая правка', 'completed' => true, 'version' => 1])->assertNotFound();
    $this->get('/participation')->assertOk()->assertInertia(fn (Assert $page) => $page->where('participations.total', 0));
});

it('preserves participation when existing personal marks are cleared', function () {
    [$user, $query, $tender] = participationFixture();
    $url = '/tenders/'.$tender->id;
    $this->actingAs($user)->putJson($url.'/participation', ['stage' => 'submitted', 'version' => 0])->assertOk();
    $this->patchJson($url.'/state', ['status' => 'new'])->assertOk();
    $this->get('/participation?stage=submitted')->assertOk()->assertInertia(fn (Assert $page) => $page->where('participations.total', 1)->where('counts.submitted', 1));
    $this->get('/participation?stage=won')->assertOk()->assertInertia(fn (Assert $page) => $page->where('participations.total', 0)->where('counts.submitted', 1));
});

it('uses local month boundaries and combines deadlines actions and unfinished tasks without duplicate matches', function () {
    [$user, $query, $tender] = participationFixture();
    NotificationPreference::query()->create(['user_id' => $user->id, 'timezone' => 'Europe/Moscow']);
    TenderUserState::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'status' => 'favorite', 'next_action_on' => '2026-09-16']);
    $second = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Второй мониторинг', 'keywords' => ['сервер'], 'status' => 'active']);
    TenderQueryMatch::query()->create(['search_query_id' => $second->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);
    $p = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    $p->items()->create(['title' => 'Открытая задача', 'due_on' => '2026-09-17']);
    $p->items()->create(['title' => 'Закрытая задача', 'due_on' => '2026-09-17', 'completed_at' => now()]);
    $p->items()->create(['title' => 'Без срока']);
    $this->actingAs($user)->get('/calendar?month=2026-09')->assertOk()->assertInertia(fn (Assert $page) => $page->component('TenderCalendar')->has('events', 2)->where('events.0.kind', 'action')->where('events.1.kind', 'task'));
    $this->get('/calendar?month=2026-10')->assertOk()->assertInertia(fn (Assert $page) => $page->has('events', 1)->where('events.0.date', '2026-10-01')->where('events.0.kind', 'deadline'));
    $this->get('/calendar/export?month=2026-10')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('DTSTART:20260930T223000Z', false);
    $this->get('/calendar/export?month=2026-09')->assertOk()->assertSee('DTSTART;VALUE=DATE:20260916', false)->assertSee('DTEND;VALUE=DATE:20260917', false);
    $this->getJson('/calendar?month=2026-13')->assertUnprocessable();
});

it('excludes hidden tenders and foreign actions tasks and exports', function () {
    [$user, $query, $tender] = participationFixture();
    $p = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'studying']);
    $p->items()->create(['title' => 'Личная задача', 'due_on' => '2026-09-17']);
    $other = User::factory()->create();
    $calendar = app(TenderCalendarService::class);
    expect($calendar->events($other, '2026-09'))->toBe([]);
    $this->actingAs($other)->get('/calendar/export?month=2026-09')->assertOk()->assertDontSee('BEGIN:VEVENT', false);
    TenderUserState::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'status' => 'dismissed', 'next_action_on' => '2026-09-16']);
    expect($calendar->events($user, '2026-09'))->toBe([])->and($calendar->events($user, '2026-10'))->toBe([]);
});

it('escapes and folds ICS text safely and keeps event UIDs stable after deadline changes', function () {
    [$user, $query, $tender] = participationFixture();
    $tender->update(['title' => str_repeat('Закупка;', 30).",\\\r\nBEGIN:VEVENT", 'deadline_at' => '2026-09-20 12:00:00']);
    $calendar = app(TenderCalendarService::class);
    $events = $calendar->events($user, '2026-09');
    $ics = $calendar->ics($events);
    expect(substr_count($ics, "\r\nBEGIN:VEVENT\r\n"))->toBe(1);
    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
    $unfolded = str_replace("\r\n ", '', $ics);
    expect($unfolded)->toContain('Закупка\\;')->toContain('\\nBEGIN:VEVENT');
    $tender->update(['deadline_at' => '2026-09-21 13:00:00']);
    expect($calendar->events($user, '2026-09')[0]['id'])->toBe($events[0]['id']);
});

it('allows only the operators own local snapshot tenders', function () {
    [$user, $query, $tender] = participationFixture();
    $tender->update(['source' => 'eis_rss']);
    $operator = User::factory()->create(['role' => UserRole::SuperAdmin]);
    LocalMvpSearchSnapshot::query()->create(['user_id' => $operator->id, 'source' => 'eis_rss', 'query' => 'Серверы', 'tender_ids' => [$tender->id]]);
    $this->actingAs($operator)->get('/tenders/'.$tender->id.'/work')->assertOk();
    $this->putJson('/tenders/'.$tender->id.'/participation', ['stage' => 'studying', 'version' => 0])->assertOk();
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))->get('/tenders/'.$tender->id.'/work')->assertNotFound();
});

it('caps checklist size and allows adding again after deletion', function () {
    [$user, $query, $tender] = participationFixture();
    $p = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    for ($i = 0; $i < 100; $i++) {
        $p->items()->create(['title' => 'Задача '.$i]);
    }
    $url = '/tenders/'.$tender->id.'/checklist';
    $this->actingAs($user)->postJson($url, ['title' => 'Лишняя задача'])->assertUnprocessable();
    expect($p->items()->count())->toBe(100);
    $this->deleteJson($url.'/'.$p->items()->firstOrFail()->id, ['version' => 1])->assertOk();
    $this->postJson($url, ['title' => 'Замена'])->assertCreated()->assertJsonCount(100, 'participation.items');
});

it('paginates participation with stage filters and excludes inaccessible rows from counts', function () {
    [$user, $query, $tender] = participationFixture();
    for ($i = 0; $i < 22; $i++) {
        $copy = $tender->replicate();
        $copy->external_id = 'page-'.$i;
        $copy->canonical_url = 'https://rostender.info/tender/page-'.$i;
        $copy->canonical_url_hash = hash('sha256', $copy->canonical_url);
        $copy->save();
        TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $copy->id, 'matched_at' => now(), 'match_reasons' => []]);
        TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $copy->id, 'stage' => $i === 21 ? 'won' : 'preparing']);
    }
    TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'lost', 'loss_reason' => 'Нет доступа']);
    TenderQueryMatch::query()->where('tender_id', $tender->id)->delete();
    $this->actingAs($user)->get('/participation?stage=preparing&page=2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('participations.data', 1)
            ->where('participations.total', 21)->where('participations.current_page', 2)
            ->where('counts.preparing', 21)->where('counts.won', 1)->missing('counts.lost')
            ->where('participations.prev_page_url', fn ($url) => str_contains($url, 'stage=preparing')));
});

it('rejects unauthenticated access to participation and calendar endpoints', function () {
    $this->getJson('/participation')->assertUnauthorized();
    $this->getJson('/calendar')->assertUnauthorized();
    $this->getJson('/calendar/export')->assertUnauthorized();
});
