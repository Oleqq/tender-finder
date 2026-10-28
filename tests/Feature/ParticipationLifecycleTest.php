<?php

use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\ParticipationAnalyticsService;
use App\Services\ParticipationApprovalService;
use App\Services\ParticipationCommentService;
use App\Services\TaskReminderService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderCalendarService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Fixtures/team-workspace.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

function lifecycleFixture(): array
{
    $user = User::factory()->withAccess()->create();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Поиск', 'keywords' => ['сайт'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => 'lifecycle-1', 'title' => 'Разработка сайта',
        'canonical_url' => 'https://example.test/lifecycle', 'canonical_url_hash' => hash('sha256', 'lifecycle'),
        'deadline_at' => now()->addDays(2), 'budget_amount' => 100000]);
    TenderQueryMatch::query()->create(['tender_id' => $tender->id, 'search_query_id' => $query->id, 'matched_at' => now(), 'match_reasons' => []]);
    $p = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'preparing', 'version' => 1, 'planned_revenue' => 100000]);

    return [$user, $tender, $p];
}

function lifecycleItems(TenderParticipation ...$rows): array
{
    return ['items' => array_map(fn ($p) => ['id' => $p->id, 'version' => $p->version ?? $p->fresh()->version], $rows)];
}

it('removes private work from lists analytics calendar dashboard and restores the same documents and tasks', function () {
    Storage::fake('local');
    [$user, $tender, $p] = lifecycleFixture();
    $item = $p->items()->create(['title' => 'Подготовить документы', 'due_on' => '2026-09-15', 'reminder_enabled' => true]);
    TenderUserState::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'status' => 'favorite',
        'next_action_on' => '2026-09-14', 'deadline_reminders_enabled' => true, 'action_reminder_enabled' => true, 'watch_changes' => true]);
    $root = '/tenders/'.$tender->id;
    $this->actingAs($user);
    $document = $this->post($root.'/documents', ['title' => 'Предложение', 'type' => 'proposal', 'status' => 'ready',
        'file' => UploadedFile::fake()->create('proposal.pdf', 8, 'application/pdf')])->assertCreated()->json('documents.0');
    $download = route('tenders.documents.download', ['tender' => $tender->id, 'document' => $document['id'], 'version' => $document['versions'][0]['id']], false);
    $calendar = app(TenderCalendarService::class);
    expect($calendar->events($user, '2026-09'))->toHaveCount(3);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('nextActions.today_count', 1));
    $removed = $this->postJson('/participation/remove', lifecycleItems($p))->assertOk()->assertJsonPath('count', 1)->json('items');
    expect(TenderParticipation::query()->count())->toBe(0)
        ->and(TenderParticipation::onlyTrashed()->count())->toBe(1)
        ->and(Tender::query()->whereKey($tender->id)->exists())->toBeTrue()
        ->and($item->fresh()->reminder_enabled)->toBeFalse()
        ->and($calendar->events($user, '2026-09'))->toBe([])
        ->and(app(ParticipationAnalyticsService::class)->snapshot($user, null, 'all')['summary']['total'])->toBe(0);
    $this->get('/calendar/export?month=2026-09')->assertDontSee('BEGIN:VEVENT', false);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('nextActions.today_count', 0)->has('nextActions.items', 0));
    $this->get('/participation')->assertInertia(fn (Assert $page) => $page->where('participations.total', 0)->where('deletedCount', 1));
    $this->get('/tenders')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 0));
    $this->get('/participation?view=deleted')->assertInertia(fn (Assert $page) => $page->where('participations.data.0.id', $p->id));
    $this->get($download)->assertNotFound();
    $this->get($root.'/work')->assertInertia(fn (Assert $page) => $page->where('participation', null)->where('removedParticipation.id', $p->id)->has('documents', 0));
    $this->putJson($root.'/participation', ['stage' => 'studying', 'version' => 0])->assertConflict();
    $this->postJson($root.'/checklist', ['title' => 'Не воскресить'])->assertNotFound();
    $this->postJson('/participation/restore', ['items' => $removed])->assertOk();
    $this->get($download)->assertOk();
    $this->get('/tenders')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 1));
    $this->get($root.'/work')->assertInertia(fn (Assert $page) => $page->where('participation.id', $p->id)->has('participation.items', 1)->has('documents', 1));
    expect($item->fresh()->reminder_enabled)->toBeFalse()
        ->and(TenderUserState::query()->sole()->watch_changes)->toBeFalse()
        ->and(TenderParticipation::query()->sole()->version)->toBe(3);
});

it('validates whole batches atomically without touching foreign or stale work', function () {
    [$user, $tender, $p] = lifecycleFixture();
    $other = TenderParticipation::query()->create(['user_id' => User::factory()->withAccess()->create()->id, 'tender_id' => $tender->id]);
    $this->actingAs($user)->postJson('/participation/remove', lifecycleItems($p, $other))->assertNotFound();
    expect($p->fresh()->trashed())->toBeFalse();
    $this->postJson('/participation/remove', ['items' => [['id' => $p->id, 'version' => 2]]])->assertConflict();
    $this->postJson('/participation/remove', lifecycleItems($p, $p))->assertUnprocessable();
    $this->postJson('/participation/remove', ['items' => []])->assertUnprocessable();
    $this->postJson('/participation/remove', ['items' => [['id' => $p->id]]])->assertUnprocessable();
    $this->postJson('/participation/restore', lifecycleItems($p))->assertConflict();
    $this->postJson('/participation/remove', lifecycleItems($p))->assertOk();
    $this->postJson('/participation/restore', lifecycleItems($p))->assertConflict();
    $this->postJson('/participation/remove', lifecycleItems($p))->assertConflict();
    expect($other->fresh()->trashed())->toBeFalse();
});

it('separates expired pre-submission work without losing submitted results or guessing losses', function () {
    [$user, $tender, $p] = lifecycleFixture();
    $tender->update(['deadline_at' => now()]);
    $query = SearchQuery::query()->where('user_id', $user->id)->sole();
    foreach (['submitted', 'won', 'lost'] as $stage) {
        $t = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => $stage, 'title' => $stage,
            'canonical_url' => 'https://example.test/'.$stage, 'canonical_url_hash' => hash('sha256', $stage), 'deadline_at' => now()->subDay()]);
        TenderQueryMatch::query()->create(['tender_id' => $t->id, 'search_query_id' => $query->id, 'matched_at' => now(), 'match_reasons' => []]);
        TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $t->id, 'stage' => $stage]);
    }
    $this->actingAs($user)->get('/participation')->assertInertia(fn (Assert $page) => $page->where('participations.total', 3)->where('expiredCount', 1)->where('counts.submitted', 1));
    $this->get('/participation?view=expired')->assertInertia(fn (Assert $page) => $page->where('participations.total', 1)->where('participations.data.0.id', $p->id)->where('participations.data.0.expired_before_submission', true));
    $snapshot = app(ParticipationAnalyticsService::class)->snapshot($user, null, 'all');
    expect($snapshot['summary']['active'])->toBe(1)->and($snapshot['summary']['pipeline_amount'])->toEqual(0);
    expect($p->fresh()->stage->value)->toBe('preparing');
    $tender->update(['deadline_at' => now()->addSecond()]);
    $this->get('/participation')->assertInertia(fn (Assert $page) => $page->where('expiredCount', 0)->where('participations.total', 4));
    $tender->update(['deadline_at' => null]);
    $this->get('/participation')->assertInertia(fn (Assert $page) => $page->where('expiredCount', 0));
});

it('protects team and personal scopes and read-only or archived teams', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $tender->update(['deadline_at' => now()->addDay()]);
    $p = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id, 'assignee_id' => $member->id]);
    $personal = TenderParticipation::query()->create(['user_id' => $owner->id, 'tender_id' => $tender->id]);
    $task = $p->items()->create(['title' => 'Задача', 'assignee_id' => $member->id, 'due_on' => now()->subDay()]);
    $url = '/participation/remove?team_id='.$team->id;
    $this->actingAs($viewer)->postJson($url, lifecycleItems($p))->assertForbidden();
    $this->actingAs(User::factory()->withAccess()->create())->postJson($url, lifecycleItems($p))->assertNotFound();
    $this->actingAs($owner)->postJson('/participation/remove', lifecycleItems($p))->assertNotFound();
    $this->postJson($url, lifecycleItems($personal))->assertNotFound();
    $this->actingAs($member)->postJson($url, lifecycleItems($p))->assertOk();
    expect($personal->fresh()->trashed())->toBeFalse();
    $members = collect(app(TeamWorkspaceService::class)->props($owner, $team)['members']);
    expect($members->firstWhere('id', $member->id)->open_tasks)->toBe(0)
        ->and($members->firstWhere('id', $member->id)->active_applications)->toBe(0);
    $team->update(['archived_at' => now()]);
    $this->postJson('/participation/restore?team_id='.$team->id, lifecycleItems($p->fresh()))->assertConflict();
    expect($task->fresh())->not->toBeNull();
});

it('stops queued reminders mentions and approvals through removal and restoration', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $member->update(['telegram_id' => 'lifecycle-notifications']);
    Entitlement::query()->create(['user_id' => $member->id, 'code' => 'active_queries', 'status' => 'active', 'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    $p = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    $task = $p->items()->create(['title' => 'Напоминание', 'assignee_id' => $member->id, 'due_on' => now()->addDay(), 'reminder_enabled' => true]);
    $this->actingAs($owner)->postJson('/tenders/'.$tender->id.'/comments?team_id='.$team->id, ['body' => 'Проверка', 'mention_ids' => [$member->id]])->assertCreated();
    $mention = NotificationDelivery::query()->where('type', 'team_mention')->sole();
    $approval = $p->approvalRequests()->create(['requested_by_id' => $owner->id, 'status' => 'pending', 'required_approvals' => 1, 'economics_version' => $p->fresh()->economics_version, 'version' => 1]);
    $approvalDelivery = NotificationDelivery::query()->create(['user_id' => $member->id, 'tender_id' => $tender->id, 'type' => 'participation_approval', 'status' => 'queued', 'scheduled_at' => now(), 'idempotency_key' => 'lifecycle-approval', 'payload' => ['approval_id' => $approval->id, 'event' => 'requested']]);
    expect(app(TaskReminderService::class)->recipient($task->fresh())?->id)->toBe($member->id)
        ->and(app(ParticipationCommentService::class)->stillDue($mention))->toBeTrue()
        ->and(app(ParticipationApprovalService::class)->stillDue($approvalDelivery))->toBeTrue();
    app(TaskReminderService::class)->queueDue();
    $taskDelivery = NotificationDelivery::query()->where('type', 'task_reminder')->sole();
    expect(app(TaskReminderService::class)->stillDue($taskDelivery))->toBeTrue();
    $removed = $this->postJson('/participation/remove?team_id='.$team->id, lifecycleItems($p))->assertOk()->json('items');
    expect(app(TaskReminderService::class)->stillDue($taskDelivery))->toBeFalse()
        ->and(app(TaskReminderService::class)->recipient($task->fresh()))->toBeNull()
        ->and(app(ParticipationCommentService::class)->stillDue($mention))->toBeFalse()
        ->and(app(ParticipationApprovalService::class)->stillDue($approvalDelivery))->toBeFalse();
    $this->patchJson('/tenders/'.$tender->id.'/approval-requests/'.$approval->id.'/vote?team_id='.$team->id, ['decision' => 'approved', 'version' => 1])->assertNotFound();
    $this->postJson('/participation/restore?team_id='.$team->id, ['items' => $removed])->assertOk();
    expect(app(TaskReminderService::class)->stillDue($taskDelivery))->toBeFalse()
        ->and(app(TaskReminderService::class)->recipient($task->fresh()))->toBeNull()
        ->and(app(ParticipationCommentService::class)->stillDue($mention))->toBeFalse()
        ->and(app(ParticipationApprovalService::class)->stillDue($approvalDelivery))->toBeFalse();
});

it('does not retain hidden personal actions on the dashboard', function () {
    [$user, $tender] = lifecycleFixture();
    TenderUserState::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'status' => 'dismissed', 'next_action_on' => now()->subDay()]);
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('nextActions.overdue_count', 0)->has('nextActions.items', 0));
});

it('removes and restores several owned rows as one operation and rolls back a stale batch', function () {
    [$user, $tender, $p] = lifecycleFixture();
    $secondTender = $tender->replicate();
    $secondTender->external_id = 'lifecycle-2';
    $secondTender->canonical_url_hash = hash('sha256', 'lifecycle-2');
    $secondTender->save();
    TenderQueryMatch::query()->create(['tender_id' => $secondTender->id, 'search_query_id' => SearchQuery::query()->sole()->id, 'matched_at' => now(), 'match_reasons' => []]);
    $second = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $secondTender->id, 'version' => 1]);
    $stale = lifecycleItems($p, $second);
    $stale['items'][1]['version'] = 2;
    $this->actingAs($user)->postJson('/participation/remove', $stale)->assertConflict();
    expect(TenderParticipation::query()->count())->toBe(2);
    $removed = $this->postJson('/participation/remove', lifecycleItems($p, $second))->assertOk()->assertJsonPath('count', 2)->json('items');
    expect(TenderParticipation::query()->count())->toBe(0);
    $this->postJson('/participation/restore', ['items' => $removed])->assertOk()->assertJsonPath('count', 2);
    expect(TenderParticipation::query()->count())->toBe(2)->and($second->fresh()->version)->toBe(3);
    $this->postJson('/participation/remove', ['items' => array_fill(0, 101, ['id' => $p->id, 'version' => 3])])->assertUnprocessable();
});

it('never recreates a removed team row through promotion and offers restoration to members', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => SearchQuery::query()->sole()->id, 'shared_by_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $p = TenderParticipation::query()->create(['user_id' => $owner->id, 'team_id' => $team->id, 'tender_id' => $tender->id, 'version' => 1]);
    $this->actingAs($owner)->postJson('/participation/remove?team_id='.$team->id, lifecycleItems($p))->assertOk();
    $this->postJson('/teams/'.$team->id.'/tenders/'.$tender->id.'/promote')->assertConflict();
    $this->actingAs($member)->get('/tenders/'.$tender->id.'/work?team_id='.$team->id)->assertInertia(fn (Assert $page) => $page->where('removedParticipation.id', $p->id)->where('can_edit', true));
    $this->actingAs($viewer)->get('/tenders/'.$tender->id.'/work?team_id='.$team->id)->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
    $this->postJson('/participation/restore?team_id='.$team->id, lifecycleItems($p->fresh()))->assertForbidden();
    expect(TenderParticipation::withTrashed()->count())->toBe(1);
});

it('keeps hidden feed marks reversible without hiding a second users matching tender', function () {
    [$user, $tender] = lifecycleFixture();
    $other = User::factory()->withAccess()->create();
    $query = SearchQuery::query()->create(['user_id' => $other->id, 'name' => 'Поиск', 'keywords' => ['сайт'], 'status' => 'active']);
    TenderQueryMatch::query()->create(['tender_id' => $tender->id, 'search_query_id' => $query->id, 'matched_at' => now(), 'match_reasons' => []]);
    $this->actingAs($user)->patchJson('/tenders/'.$tender->id.'/state', ['status' => 'dismissed'])->assertOk();
    $this->get('/tenders')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 0));
    $this->get('/tenders?status=dismissed')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 1));
    $this->actingAs($other)->get('/tenders')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 1));
    $this->actingAs($user)->patchJson('/tenders/'.$tender->id.'/state', ['status' => 'new'])->assertOk();
    $this->get('/tenders')->assertInertia(fn (Assert $page) => $page->where('tenderMatches.total', 1));
});
