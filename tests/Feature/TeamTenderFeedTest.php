<?php

use App\Models\SearchQuery;
use App\Models\TenderParticipation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Fixtures/team-workspace.php';

it('shares only an editors own monitoring and keeps unshared matches private', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $ownerQuery = SearchQuery::query()->where('user_id', $owner->id)->sole();
    $memberQuery = SearchQuery::query()->create(['user_id' => $member->id, 'name' => 'Мониторинг участника', 'keywords' => ['услуги'], 'status' => 'active']);

    $this->actingAs($member)->postJson('/teams/'.$team->id.'/monitorings', ['search_query_id' => $ownerQuery->id])->assertNotFound();
    $this->actingAs($viewer)->postJson('/teams/'.$team->id.'/monitorings', ['search_query_id' => $ownerQuery->id])->assertForbidden();
    $this->actingAs($member)->postJson('/teams/'.$team->id.'/monitorings', ['search_query_id' => $memberQuery->id])->assertCreated();

    $this->actingAs($viewer)->get('/tenders?team_id='.$team->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tenders')->where('team.id', $team->id)->where('tenderMatches.total', 0)
        ->has('sharedMonitorings', 1)->where('can_edit', false));

    $this->actingAs($owner)->postJson('/teams/'.$team->id.'/monitorings', ['search_query_id' => $ownerQuery->id])->assertCreated();
    $this->actingAs($viewer)->get('/tenders?team_id='.$team->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tenderMatches.total', 1)->where('tenderMatches.data.0.tender_id', $tender->id)
        ->where('tenderMatches.data.0.review.status', 'new')->where('tenderMatches.data.0.review.version', 0));
});

it('deduplicates team matches and supports filtering the shared review queue', function () {
    [$owner, $member, , $team, $tender] = teamFixture();
    $ownerQuery = SearchQuery::query()->where('user_id', $owner->id)->sole();
    $memberQuery = SearchQuery::query()->create(['user_id' => $member->id, 'name' => 'Второй поиск', 'keywords' => ['закупка'], 'status' => 'active']);
    DB::table('tender_query_matches')->insert(['search_query_id' => $memberQuery->id, 'tender_id' => $tender->id, 'match_reasons' => '{}', 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ([$ownerQuery, $memberQuery] as $query) {
        DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $query->user_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->actingAs($owner)->patchJson('/teams/'.$team->id.'/tenders/'.$tender->id.'/review', [
        'status' => 'reviewing', 'assignee_id' => $member->id, 'rejection_reason' => null, 'version' => 0,
    ])->assertOk()->assertJsonPath('review.version', 1);

    $this->get('/tenders?team_id='.$team->id.'&status=reviewing&assignee_id='.$member->id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tenderMatches.total', 1)->has('tenderMatches.data.0.query_names', 2)
        ->where('tenderMatches.data.0.review.assignee_id', $member->id));
    $this->get('/tenders?team_id='.$team->id.'&status=rejected')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 0));
});

it('enforces review permissions rejection reasons and optimistic locking', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $query = SearchQuery::query()->where('user_id', $owner->id)->sole();
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $url = '/teams/'.$team->id.'/tenders/'.$tender->id.'/review';

    $this->actingAs($viewer)->patchJson($url, ['status' => 'reviewing', 'version' => 0])->assertForbidden();
    $this->actingAs($member)->patchJson($url, ['status' => 'rejected', 'version' => 0])->assertUnprocessable();
    $this->patchJson($url, ['status' => 'rejected', 'version' => 0, 'rejection_reason' => 'Не подходит лицензия'])
        ->assertOk()->assertJsonPath('review.rejection_reason', 'Не подходит лицензия');
    $this->patchJson($url, ['status' => 'qualified', 'version' => 0])->assertConflict();
});

it('keeps pre participation discussion and promotes a reviewed tender once', function () {
    [$owner, $member, , $team, $tender] = teamFixture();
    $query = SearchQuery::query()->where('user_id', $owner->id)->sole();
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $root = '/teams/'.$team->id.'/tenders/'.$tender->id;

    $this->actingAs($member)->postJson($root.'/comments', ['body' => 'Проверю требования заказчика'])
        ->assertCreated()->assertJsonPath('comment.author_id', $member->id);
    $this->postJson($root.'/promote', ['assignee_id' => $member->id])->assertCreated();
    $this->postJson($root.'/promote', ['assignee_id' => $owner->id])->assertOk();

    expect(TenderParticipation::query()->where('team_id', $team->id)->where('tender_id', $tender->id)->count())->toBe(1)
        ->and(DB::table('team_tender_review_comments')->count())->toBe(1)
        ->and(DB::table('team_tender_reviews')->where('team_id', $team->id)->value('status'))->toBe('qualified');
});

it('lets only the sharer or owner disconnect a team monitoring', function () {
    [$owner, $member, , $team] = teamFixture();
    $query = SearchQuery::query()->where('user_id', $owner->id)->sole();
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($member)->deleteJson('/teams/'.$team->id.'/monitorings/'.$query->id)->assertForbidden();
    $this->actingAs($owner)->deleteJson('/teams/'.$team->id.'/monitorings/'.$query->id)->assertOk();
    $this->assertDatabaseMissing('team_search_queries', ['team_id' => $team->id, 'search_query_id' => $query->id]);
});
