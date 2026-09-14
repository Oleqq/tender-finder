<?php

use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\SearchQuery;
use App\Models\TeamTenderReview;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Services\TeamWorkflowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 9, 15)->setTime(9, 0));
});

require_once __DIR__.'/../Fixtures/team-workspace.php';

function workflowSettingsPayload(array $overrides = []): array
{
    return [...[
        'review_sla_hours' => 8,
        'assignment_mode' => 'least_loaded',
        'notify_assignments' => true,
        'notify_sla' => true,
        'digest_enabled' => true,
        'digest_time' => '09:00',
        'approval_enabled' => false,
        'approval_min_revenue' => null,
        'approval_max_margin_percent' => null,
        'required_approvals' => 1,
        'version' => 1,
    ], ...$overrides];
}

it('configures SLA and routing rules and materializes assigned reviews', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $query = SearchQuery::query()->where('user_id', $owner->id)->sole();

    $this->actingAs($viewer)->patchJson('/teams/'.$team->id.'/workflow-settings', workflowSettingsPayload())->assertForbidden();
    $this->actingAs($owner)->patchJson('/teams/'.$team->id.'/workflow-settings', workflowSettingsPayload())
        ->assertOk()->assertJsonPath('settings.review_sla_hours', 8)->assertJsonPath('settings.version', 2);
    $this->postJson('/teams/'.$team->id.'/routing-rules', [
        'name' => 'RosTender участнику', 'priority' => 10, 'source' => 'rostender', 'search_query_id' => $query->id,
        'region' => null, 'min_budget' => null, 'assignee_id' => $member->id, 'enabled' => true,
    ])->assertUnprocessable();

    $this->postJson('/teams/'.$team->id.'/monitorings', ['search_query_id' => $query->id])->assertCreated();
    $this->postJson('/teams/'.$team->id.'/routing-rules', [
        'name' => 'RosTender участнику', 'priority' => 10, 'source' => 'rostender', 'search_query_id' => $query->id,
        'region' => null, 'min_budget' => null, 'assignee_id' => $member->id, 'enabled' => true,
    ])->assertCreated();

    // Recreate the materialized review so the newly added explicit rule is applied.
    TeamTenderReview::query()->delete();
    app(TeamWorkflowService::class)->sync($team);
    $review = TeamTenderReview::query()->sole();
    expect($review->assignee_id)->toBe($member->id)
        ->and($review->due_at?->toAtomString())->toBe(now()->addHours(8)->toAtomString());

    $this->travel(9)->hours();
    $this->actingAs($owner)->get('/tenders?team_id='.$team->id.'&overdue=1')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('tenderMatches.total', 1)->where('tenderMatches.data.0.review.overdue', true)
        ->where('workflowSettings.assignment_mode', 'least_loaded')->has('routingRules', 1));
});

it('updates up to one hundred selected reviews atomically and saves team filters', function () {
    [$owner, $member, , $team, $tender] = teamFixture();
    $query = SearchQuery::query()->where('user_id', $owner->id)->sole();
    $second = Tender::query()->create(['source' => 'rostender', 'external_id' => 'team-2',
        'canonical_url' => 'https://example.test/team-2', 'canonical_url_hash' => hash('sha256', 'team-2'), 'title' => 'Вторая закупка']);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $second->id, 'matched_at' => now(), 'match_reasons' => []]);
    $this->actingAs($owner)->postJson('/teams/'.$team->id.'/monitorings', ['search_query_id' => $query->id])->assertCreated();
    $reviews = TeamTenderReview::query()->where('team_id', $team->id)->orderBy('tender_id')->get();

    $this->patchJson('/teams/'.$team->id.'/tenders/reviews', [
        'items' => $reviews->map(fn ($review) => ['tender_id' => $review->tender_id, 'version' => $review->version])->all(),
        'status' => 'reviewing', 'assignee_id' => $member->id, 'rejection_reason' => null,
    ])->assertOk()->assertJsonCount(2, 'reviews');
    expect(TeamTenderReview::query()->where('status', 'reviewing')->where('assignee_id', $member->id)->count())->toBe(2);

    $this->postJson('/tender-feed-views', ['team_id' => $team->id, 'name' => 'Мои просроченные',
        'filters' => ['status' => 'reviewing', 'assignee_id' => $member->id, 'overdue' => true]])
        ->assertCreated()->assertJsonPath('view.team_id', $team->id);
    $this->get('/tenders?team_id='.$team->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('savedViews.0.name', 'Мои просроченные'));

    $stale = $reviews->first();
    $this->patchJson('/teams/'.$team->id.'/tenders/reviews', [
        'items' => [['tender_id' => $stale->tender_id, 'version' => $stale->version]],
        'status' => 'qualified', 'assignee_id' => $owner->id,
    ])->assertConflict();
});

it('queues assignment SLA and owner digest notifications idempotently', function () {
    [$owner, $member, , $team] = teamFixture();
    foreach ([$owner, $member] as $user) {
        $user->update(['telegram_id' => 'workflow-'.$user->id]);
        Entitlement::query()->create(['user_id' => $user->id, 'code' => 'active_queries', 'status' => 'active',
            'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    }
    $query = SearchQuery::query()->where('user_id', $owner->id)->sole();
    $this->actingAs($owner)->postJson('/teams/'.$team->id.'/monitorings', ['search_query_id' => $query->id])->assertCreated();
    $review = TeamTenderReview::query()->sole();
    $this->patchJson('/teams/'.$team->id.'/tenders/'.$review->tender_id.'/review', [
        'status' => 'reviewing', 'assignee_id' => $member->id, 'version' => $review->version,
    ])->assertOk();
    $review->refresh()->update(['due_at' => now()->subMinute(), 'sla_alerted_at' => null]);
    app(TeamWorkflowService::class)->settings($team)
        ->update(['digest_time' => now('Europe/Moscow')->format('H:i')]);

    Artisan::call('teams:process-tender-reviews');
    Artisan::call('teams:process-tender-reviews');

    expect(NotificationDelivery::query()->where('type', 'team_review_assignment')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('type', 'team_review_sla')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('type', 'team_review_digest')->count())->toBe(1);
});

it('requires multi person go no-go approval and invalidates it after economics changes', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $this->actingAs($owner)->patchJson('/teams/'.$team->id.'/workflow-settings', workflowSettingsPayload([
        'approval_enabled' => true, 'approval_min_revenue' => '1000', 'required_approvals' => 2,
    ]))->assertOk();
    $participation = TenderParticipation::query()->create([
        'team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id, 'stage' => 'studying',
        'planned_revenue' => 1500, 'planned_cost' => 900, 'participation_decision' => 'go',
    ]);
    $scope = '?team_id='.$team->id;

    $this->putJson('/tenders/'.$tender->id.'/participation'.$scope, [
        'stage' => 'preparing', 'version' => 1, 'assignee_id' => $member->id,
    ])->assertConflict();
    $response = $this->postJson('/tenders/'.$tender->id.'/approval-requests'.$scope, ['note' => 'Маржа проверена'])
        ->assertCreated()->assertJsonPath('approval.current.status', 'pending');
    $approvalId = $response->json('approval.current.id');
    $this->actingAs($viewer)->patchJson('/tenders/'.$tender->id.'/approval-requests/'.$approvalId.'/vote'.$scope,
        ['decision' => 'approved', 'version' => 1])->assertForbidden();
    $this->actingAs($member)->patchJson('/tenders/'.$tender->id.'/approval-requests/'.$approvalId.'/vote'.$scope,
        ['decision' => 'approved', 'comment' => 'Риски допустимы', 'version' => 1])
        ->assertOk()->assertJsonPath('approval.current.status', 'pending')->assertJsonCount(1, 'approval.current.votes');
    $this->actingAs($owner)->patchJson('/tenders/'.$tender->id.'/approval-requests/'.$approvalId.'/vote'.$scope,
        ['decision' => 'approved', 'version' => 2])
        ->assertOk()->assertJsonPath('approval.current.status', 'approved')->assertJsonCount(2, 'approval.current.votes');
    $this->putJson('/tenders/'.$tender->id.'/participation'.$scope, [
        'stage' => 'preparing', 'version' => 1, 'assignee_id' => $member->id,
    ])->assertOk()->assertJsonPath('participation.stage', 'preparing');

    $this->patchJson('/tenders/'.$tender->id.'/economics'.$scope, [
        'planned_revenue' => 1600, 'planned_cost' => 900, 'security_cost' => null, 'commission_cost' => null,
        'other_cost' => null, 'actual_revenue' => null, 'actual_cost' => null, 'decision' => 'go',
        'decision_note' => 'Обновили цену', 'version' => 1,
    ])->assertOk()->assertJsonPath('approval.current.status', 'superseded');
    $this->putJson('/tenders/'.$tender->id.'/participation'.$scope, [
        'stage' => 'submitted', 'version' => 2, 'assignee_id' => $member->id,
    ])->assertConflict();

    expect(DB::table('team_activity_logs')->where('action', 'approval_requested')->exists())->toBeTrue()
        ->and(DB::table('team_activity_logs')->where('action', 'approval_voted')->count())->toBe(2);
});
