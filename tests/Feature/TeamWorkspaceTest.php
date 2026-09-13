<?php

use App\Models\ChecklistTemplate;
use App\Models\Team;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

require_once __DIR__.'/../Fixtures/team-workspace.php';

it('creates a team with a server assigned owner role', function () {
    $owner = User::factory()->create();
    $id = $this->actingAs($owner)->postJson('/teams', ['name' => 'Компания', 'owner_id' => 999])->assertCreated()->json('team_id');
    expect(Team::query()->findOrFail($id)->owner_id)->toBe($owner->id);
    $this->get('/teams?team_id='.$id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('team.role', 'owner')->has('members', 1));
});

it('requires explicit invitation acceptance and stores only a hash with expiry and revocation', function () {
    [$owner, $member, $viewer, $team] = teamFixture();
    $url = $this->actingAs($owner)->postJson('/teams/'.$team->id.'/invitations', ['role' => 'member'])->assertCreated()->json('url');
    $token = basename($url);
    $invite = DB::table('team_invitations')->sole();
    expect($invite->token_hash)->toBe(hash('sha256', $token));
    $new = User::factory()->create();
    $this->actingAs($new)->get($url)->assertOk();
    expect(DB::table('team_members')->where('user_id', $new->id)->exists())->toBeFalse();
    $this->postJson($url)->assertOk()->assertJsonPath('team_id', $team->id);
    $this->postJson($url)->assertGone();
    $this->actingAs($member)->postJson('/teams/'.$team->id.'/invitations', ['role' => 'member'])->assertForbidden();
    $this->actingAs($owner)->postJson('/teams/'.$team->id.'/invitations', ['role' => 'owner'])->assertUnprocessable();
    $url2 = $this->postJson('/teams/'.$team->id.'/invitations', ['role' => 'viewer'])->json('url');
    $this->deleteJson('/teams/'.$team->id.'/invitations/'.DB::table('team_invitations')->max('id'))->assertOk();
    $this->actingAs($new)->postJson($url2)->assertGone();
    $url3 = $this->actingAs($owner)->postJson('/teams/'.$team->id.'/invitations', ['role' => 'member'])->json('url');
    $this->travel(8)->days();
    $this->actingAs($new)->postJson($url3)->assertGone();
});

it('does not change an existing members role by accepting another invitation', function () {
    [$owner, $member, $viewer, $team] = teamFixture();
    $url = $this->actingAs($owner)->postJson('/teams/'.$team->id.'/invitations', ['role' => 'member'])->json('url');
    $this->actingAs($viewer)->postJson($url)->assertOk();
    expect(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $viewer->id)->value('role'))->toBe('viewer');
});

it('isolates personal participation and tasks from explicitly shared team work', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $root = '/tenders/'.$tender->id;
    $scope = '?team_id='.$team->id;
    $this->actingAs($owner)->putJson($root.'/participation', ['stage' => 'lost', 'loss_reason' => 'Личная причина', 'version' => 0])->assertOk();
    $privateItem = $this->postJson($root.'/checklist', ['title' => 'Личная задача', 'due_on' => '2026-09-15'])->json('participation.items.0.id');
    $this->actingAs($member)->get($root.'/work'.$scope)->assertNotFound();
    $this->actingAs($owner)->putJson($root.'/participation'.$scope, ['stage' => 'studying', 'version' => 0, 'assignee_id' => $member->id])->assertOk();
    $this->actingAs($member)->get($root.'/work'.$scope)->assertOk()->assertInertia(fn (Assert $p) => $p->where('participation.loss_reason', null)->has('participation.items', 0)->where('participation.assignee_id', $member->id));
    $this->get($root.'/work')->assertNotFound();
    $this->deleteJson($root.'/checklist/'.$privateItem.$scope, ['version' => 1])->assertNotFound();
    $this->postJson($root.'/checklist'.$scope, ['title' => 'Общая задача', 'due_on' => '2026-09-16'])->assertCreated();
    $this->get('/calendar?month=2026-09&team_id='.$team->id)->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 2)->where('events.0.title', 'Общая задача'));
    $this->actingAs($owner)->get('/calendar?month=2026-09')->assertOk()->assertInertia(fn (Assert $p) => $p->has('events', 2)->where('events.0.title', 'Личная задача'));
    $this->get('/participation')->assertOk()->assertInertia(fn (Assert $p) => $p->where('participations.total', 1));
    $this->get('/participation'.$scope)->assertOk()->assertInertia(fn (Assert $p) => $p->where('participations.total', 1));
});

it('enforces viewer permissions and validates assignees against the selected team', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $root = '/tenders/'.$tender->id;
    $scope = '?team_id='.$team->id;
    $this->actingAs($owner)->putJson($root.'/participation'.$scope, ['stage' => 'studying', 'version' => 0])->assertOk();
    $id = $this->postJson($root.'/checklist'.$scope, ['title' => 'Задача'])->json('participation.items.0.id');
    $this->actingAs($viewer)->get($root.'/work'.$scope)->assertOk()->assertInertia(fn (Assert $p) => $p->where('can_edit', false));
    $this->putJson($root.'/participation'.$scope, ['stage' => 'won', 'version' => 1])->assertForbidden();
    $this->postJson($root.'/checklist'.$scope, ['title' => 'Правка'])->assertForbidden();
    $this->deleteJson($root.'/checklist/'.$id.$scope, ['version' => 1])->assertForbidden();
    $this->actingAs($member)->putJson($root.'/participation'.$scope, ['stage' => 'studying', 'version' => 1, 'assignee_id' => $viewer->id])->assertUnprocessable();
    $this->putJson($root.'/participation'.$scope, ['stage' => 'preparing', 'version' => 1, 'assignee_id' => User::factory()->create()->id])->assertUnprocessable();
    $this->putJson($root.'/participation'.$scope, ['stage' => 'preparing', 'version' => 1, 'assignee_id' => $member->id])->assertOk();
    $this->actingAs($owner)->putJson($root.'/participation'.$scope, ['stage' => 'won', 'version' => 1])->assertConflict();
    $this->actingAs(User::factory()->create())->get('/calendar/export?month=2026-09&team_id='.$team->id)->assertNotFound();
});

it('removes access and assignments when a member leaves or becomes a viewer', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $p = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id, 'assignee_id' => $member->id]);
    $item = $p->items()->create(['title' => 'Задача', 'assignee_id' => $member->id]);
    $this->actingAs($member)->deleteJson('/teams/'.$team->id.'/members/'.$owner->id)->assertForbidden();
    $this->actingAs($owner)->patchJson('/teams/'.$team->id.'/members/'.$member->id, ['role' => 'viewer'])->assertOk();
    expect($p->fresh()->assignee_id)->toBeNull()->and($p->fresh()->version)->toBe(2)->and($item->fresh()->assignee_id)->toBeNull()->and($item->fresh()->version)->toBe(2);
    $this->deleteJson('/teams/'.$team->id.'/members/'.$owner->id)->assertUnprocessable();
    $this->actingAs($member)->deleteJson('/teams/'.$team->id.'/members/'.$member->id)->assertOk();
    $this->get('/tenders/'.$tender->id.'/work?team_id='.$team->id)->assertNotFound();
});

it('keeps templates scoped and applies them atomically without duplicates or copying dates', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $scope = '?team_id='.$team->id;
    $root = '/tenders/'.$tender->id;
    $private = $this->actingAs($owner)->postJson('/checklist-templates', ['name' => 'Личный', 'items' => ['Личная задача']])->assertCreated()->json('template.id');
    $shared = $this->postJson('/checklist-templates'.$scope, ['name' => 'Подготовка', 'items' => ['Документы', 'Цена']])->assertCreated()->json('template.id');
    $this->putJson($root.'/participation'.$scope, ['stage' => 'preparing', 'version' => 0])->assertOk();
    $this->actingAs($member)->get($root.'/work'.$scope)->assertOk()->assertInertia(fn (Assert $p) => $p->has('templates', 1)->where('templates.0.id', $shared));
    $this->postJson($root.'/templates/'.$private.$scope)->assertNotFound();
    $this->postJson($root.'/templates/'.$shared.$scope)->assertOk()->assertJsonCount(2, 'participation.items')->assertJsonPath('participation.items.0.due_on', null)->assertJsonPath('participation.items.0.reminder_enabled', false);
    $this->postJson($root.'/templates/'.$shared.$scope)->assertOk()->assertJsonCount(2, 'participation.items');
    $this->actingAs($viewer)->postJson($root.'/templates/'.$shared.$scope)->assertForbidden();
    $this->deleteJson('/checklist-templates/'.$shared.$scope)->assertForbidden();
    $this->actingAs($member)->deleteJson('/checklist-templates/'.$shared.$scope)->assertOk();
    expect(TenderParticipation::query()->where('team_id', $team->id)->sole()->items()->count())->toBe(2);
    expect(ChecklistTemplate::query()->find($private))->not->toBeNull();
});

it('rolls back template application when the checklist limit would be exceeded', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $p = TenderParticipation::query()->create(['user_id' => $owner->id, 'tender_id' => $tender->id]);
    for ($i = 0; $i < 99; $i++) {
        $p->items()->create(['title' => 'Задача '.$i]);
    }
    $id = $this->actingAs($owner)->postJson('/checklist-templates', ['name' => 'Две', 'items' => ['А', 'Б']])->json('template.id');
    $this->postJson('/tenders/'.$tender->id.'/templates/'.$id)->assertUnprocessable();
    expect($p->items()->count())->toBe(99)->and(DB::table('checklist_template_applications')->count())->toBe(0);
});

it('transfers ownership atomically to an editing member', function () {
    [$owner, $member, $viewer, $team] = teamFixture();

    $this->actingAs($owner)->postJson('/teams/'.$team->id.'/transfer-ownership', ['member_id' => $viewer->id])->assertUnprocessable();
    $this->postJson('/teams/'.$team->id.'/transfer-ownership', ['member_id' => $member->id])->assertOk();

    expect($team->fresh()->owner_id)->toBe($member->id)
        ->and(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $owner->id)->value('role'))->toBe('member')
        ->and(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->value('role'))->toBe('owner')
        ->and(DB::table('team_activity_logs')->where('team_id', $team->id)->where('action', 'ownership_transferred')->exists())->toBeTrue();

    $this->postJson('/teams/'.$team->id.'/invitations', ['role' => 'member'])->assertForbidden();
    $this->actingAs($member)->postJson('/teams/'.$team->id.'/invitations', ['role' => 'member'])->assertCreated();
});

it('archives teams as read only and only deletes an archived team after exact confirmation', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id]);

    $this->actingAs($owner)->deleteJson('/teams/'.$team->id, ['name' => $team->name])->assertConflict();
    $this->patchJson('/teams/'.$team->id.'/archive', ['archived' => true])->assertOk();
    $this->actingAs($member)->postJson('/tenders/'.$tender->id.'/checklist?team_id='.$team->id, ['title' => 'Новая задача'])->assertConflict();
    $this->actingAs($owner)->deleteJson('/teams/'.$team->id, ['name' => 'Другая команда'])->assertUnprocessable();
    $this->patchJson('/teams/'.$team->id.'/archive', ['archived' => false])->assertOk();
    $this->postJson('/teams/'.$team->id.'/invitations', ['role' => 'viewer'])->assertCreated();
    $this->patchJson('/teams/'.$team->id.'/archive', ['archived' => true])->assertOk();
    $this->deleteJson('/teams/'.$team->id, ['name' => $team->name])->assertOk()->assertJsonPath('deleted', true);

    expect(Team::query()->find($team->id))->toBeNull()
        ->and(TenderParticipation::query()->where('team_id', $team->id)->exists())->toBeFalse();
});

it('enforces the active team limit when restoring an archived team', function () {
    [$owner, $member, $viewer, $team] = teamFixture();
    $team->update(['archived_at' => now()]);
    foreach (range(1, 10) as $number) {
        Team::query()->create(['name' => 'Команда '.$number, 'owner_id' => $owner->id]);
    }

    $this->actingAs($owner)->patchJson('/teams/'.$team->id.'/archive', ['archived' => false])->assertUnprocessable();
    expect($team->fresh()->archived_at)->not->toBeNull();
});

it('versions edited templates and applies each version once', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $scope = '?team_id='.$team->id;
    TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id]);
    $template = $this->actingAs($owner)->postJson('/checklist-templates'.$scope, ['name' => 'Проверка', 'items' => ['Первая']])
        ->assertCreated()->json('template');

    $this->postJson('/tenders/'.$tender->id.'/templates/'.$template['id'].$scope)->assertOk()->assertJsonCount(1, 'participation.items');
    $updated = $this->patchJson('/checklist-templates/'.$template['id'].$scope, ['name' => 'Полная проверка', 'items' => ['Первая', 'Вторая'], 'version' => 1])
        ->assertOk()->assertJsonPath('template.version', 2)->json('template');
    $this->patchJson('/checklist-templates/'.$template['id'].$scope, ['name' => 'Старая правка', 'items' => ['Третья'], 'version' => 1])->assertConflict();
    $this->postJson('/tenders/'.$tender->id.'/templates/'.$template['id'].$scope)->assertOk()->assertJsonCount(3, 'participation.items');
    $this->postJson('/tenders/'.$tender->id.'/templates/'.$template['id'].$scope)->assertOk()->assertJsonCount(3, 'participation.items');

    expect($updated['name'])->toBe('Полная проверка')
        ->and(DB::table('checklist_template_versions')->where('template_id', $template['id'])->count())->toBe(2)
        ->and(DB::table('checklist_template_applications')->where('template_id', $template['id'])->count())->toBe(2);
});

it('shows workload and recent activity only to team members', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $participation = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id,
        'stage' => 'preparing', 'assignee_id' => $member->id]);
    $participation->items()->create(['title' => 'Просрочена', 'assignee_id' => $member->id, 'due_on' => now()->subDay()]);
    $participation->items()->create(['title' => 'Без срока', 'assignee_id' => $member->id]);
    DB::table('team_activity_logs')->insert(['team_id' => $team->id, 'actor_id' => $owner->id, 'action' => 'task_created',
        'context' => json_encode(['task_id' => 1]), 'created_at' => now()]);

    $this->actingAs($member)->get('/teams?team_id='.$team->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('members.1.active_applications', 1)->where('members.1.open_tasks', 2)->where('members.1.overdue_tasks', 1)
        ->where('activities.0.action', 'task_created'));
    $this->actingAs(User::factory()->create())->get('/teams?team_id='.$team->id)->assertNotFound();
});
