<?php

use App\Jobs\DeliverTelegramNotification;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\ParticipationComment;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\AccessService;
use App\Services\ParticipationCommentService;
use App\Services\TelegramBotClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
});

require_once __DIR__.'/../Fixtures/team-workspace.php';

it('supports team comments mentions unread state and immutable edit history', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $participation = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id,
        'tender_id' => $tender->id, 'stage' => 'preparing']);
    $root = '/tenders/'.$tender->id.'/comments';
    $scope = '?team_id='.$team->id;

    $response = $this->actingAs($owner)->postJson($root.$scope, ['body' => 'Иван, проверьте расчёт', 'mention_ids' => [$member->id]])
        ->assertCreated()->assertJsonPath('comments.0.body', 'Иван, проверьте расчёт')->assertJsonPath('comments.0.mentions.0.id', $member->id);
    $commentId = $response->json('comment_id');
    expect(NotificationDelivery::query()->where('type', 'team_mention')->where('user_id', $member->id)->count())->toBe(1)
        ->and(DB::table('participation_comment_versions')->where('comment_id', $commentId)->count())->toBe(1);

    $this->actingAs($member)->get('/participation'.$scope)->assertOk()->assertInertia(fn (Assert $page) => $page->where('participations.data.0.unread_comments', 1));
    $this->get('/tenders/'.$tender->id.'/work'.$scope)->assertOk()->assertInertia(fn (Assert $page) => $page->where('comments.0.body', 'Иван, проверьте расчёт'));
    $this->get('/participation'.$scope)->assertOk()->assertInertia(fn (Assert $page) => $page->where('participations.data.0.unread_comments', 0));

    $this->patchJson($root.'/'.$commentId.$scope, ['body' => 'Чужая правка', 'version' => 1, 'mention_ids' => []])->assertForbidden();
    $this->actingAs($owner)->patchJson($root.'/'.$commentId.$scope, ['body' => 'Проверьте новую маржу', 'version' => 1, 'mention_ids' => [$member->id]])
        ->assertOk()->assertJsonPath('comments.0.version', 2)->assertJsonCount(2, 'comments.0.history');
    $this->deleteJson($root.'/'.$commentId.$scope, ['version' => 1])->assertConflict();
    $this->deleteJson($root.'/'.$commentId.$scope, ['version' => 2])->assertOk()->assertJsonPath('comments.0.body', null)
        ->assertJsonCount(3, 'comments.0.history');

    expect(ParticipationComment::query()->findOrFail($commentId)->deleted_at)->not->toBeNull();
    $this->actingAs($viewer)->postJson($root.$scope, ['body' => 'Нельзя писать'])->assertForbidden();
});

it('queues a valid mention only for team members and skips it after reading', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $participation = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id]);
    $outsider = User::factory()->create();
    $url = '/tenders/'.$tender->id.'/comments?team_id='.$team->id;

    $this->actingAs($owner)->postJson($url, ['body' => 'Проверка', 'mention_ids' => [$outsider->id]])->assertUnprocessable();
    $response = $this->postJson($url, ['body' => 'Проверка', 'mention_ids' => [$member->id]])->assertCreated();
    $delivery = NotificationDelivery::query()->where('type', 'team_mention')->sole();
    expect(app(ParticipationCommentService::class)->stillDue($delivery))->toBeTrue();

    $newer = ParticipationComment::query()->create(['participation_id' => $participation->id, 'author_id' => $owner->id,
        'body' => 'Параллельный комментарий', 'version' => 1]);
    DB::table('participation_comment_mentions')->insert(['comment_id' => $newer->id, 'user_id' => $member->id,
        'created_at' => now(), 'updated_at' => now()]);
    app(ParticipationCommentService::class)->markRead($participation, $member, (int) $response->json('comment_id'));

    expect(app(ParticipationCommentService::class)->stillDue($delivery))->toBeFalse()
        ->and(DB::table('participation_comment_mentions')->where('comment_id', $newer->id)->value('read_at'))->toBeNull()
        ->and(app(ParticipationCommentService::class)->unreadCount($participation, $member))->toBe(1);
});

it('delivers a team mention with the author tender and workspace link', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $member->update(['telegram_id' => 'mentioned-member']);
    Entitlement::query()->create(['user_id' => $member->id, 'code' => 'active_queries', 'status' => 'active', 'value' => 3,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id]);
    $this->actingAs($owner)->postJson('/tenders/'.$tender->id.'/comments?team_id='.$team->id,
        ['body' => 'Проверьте финансовую модель', 'mention_ids' => [$member->id]])->assertCreated();
    $delivery = NotificationDelivery::query()->where('type', 'team_mention')->sole();
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldReceive('sendNotification')->once()->with('mentioned-member', Mockery::on(fn (string $text): bool => str_contains($text, $owner->name)
        && str_contains($text, $tender->title) && str_contains($text, 'team_id='.$team->id)));

    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));
    expect($delivery->fresh()->status->value)->toBe('sent');
});

it('calculates participation economics with independent conflict protection', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id]);
    $url = '/tenders/'.$tender->id.'/economics?team_id='.$team->id;
    $data = ['planned_revenue' => '1000.00', 'planned_cost' => '500.00', 'security_cost' => '100.00',
        'commission_cost' => '50.00', 'other_cost' => '20.00', 'actual_revenue' => '900.00', 'actual_cost' => '650.00',
        'decision' => 'go', 'decision_note' => 'Маржа приемлема', 'version' => 1];

    $this->actingAs($member)->patchJson($url, $data)->assertOk()
        ->assertJsonPath('economics.planned_expenses', 670)->assertJsonPath('economics.planned_margin', 330)
        ->assertJsonPath('economics.planned_margin_percent', 33)->assertJsonPath('economics.actual_margin', 250)
        ->assertJsonPath('economics.decision', 'go')->assertJsonPath('economics.version', 2);
    $this->patchJson($url, $data)->assertConflict();
    $this->actingAs($viewer)->patchJson($url, [...$data, 'version' => 2])->assertForbidden();
    $this->actingAs($owner)->patchJson($url, [...$data, 'planned_revenue' => '-1', 'version' => 2])->assertUnprocessable();
    expect(DB::table('team_activity_logs')->where('action', 'economics_updated')->exists())->toBeTrue();
});

it('builds scoped participation analytics and exports the same summary', function () {
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $tender->update(['budget_amount' => 1000]);
    $won = TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $tender->id,
        'assignee_id' => $member->id, 'stage' => 'won', 'planned_revenue' => 800, 'planned_cost' => 500, 'actual_revenue' => 750, 'actual_cost' => 600]);
    DB::table('tender_participation_events')->insert(['participation_id' => $won->id, 'from_stage' => 'submitted', 'to_stage' => 'won',
        'actor_id' => $owner->id, 'created_at' => now()->addDays(4)]);
    $lostTender = $tender->replicate();
    $lostTender->external_id = 'analytics-lost';
    $lostTender->canonical_url = 'https://example.test/analytics-lost';
    $lostTender->canonical_url_hash = hash('sha256', $lostTender->canonical_url);
    $lostTender->budget_amount = 2000;
    $lostTender->save();
    TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $lostTender->id,
        'assignee_id' => $member->id, 'stage' => 'lost', 'loss_reason' => 'Цена конкурента']);
    $activeTender = $tender->replicate();
    $activeTender->external_id = 'analytics-active';
    $activeTender->canonical_url = 'https://example.test/analytics-active';
    $activeTender->canonical_url_hash = hash('sha256', $activeTender->canonical_url);
    $activeTender->budget_amount = 3000;
    $activeTender->save();
    TenderParticipation::query()->create(['team_id' => $team->id, 'user_id' => $owner->id, 'tender_id' => $activeTender->id,
        'assignee_id' => $owner->id, 'stage' => 'preparing']);
    $url = '/participation/analytics?period=90&team_id='.$team->id;

    $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ParticipationAnalytics')
        ->where('analytics.summary.total', 3)->where('analytics.summary.active', 1)->where('analytics.summary.win_rate', 50)
        ->where('analytics.summary.pipeline_amount', 3000)->where('analytics.summary.won_amount', 1000)
        ->where('analytics.summary.planned_margin', 300)->where('analytics.summary.actual_margin', 150)
        ->where('analytics.loss_reasons.0.reason', 'Цена конкурента')->where('analytics.members.0.name', $member->name));
    $this->get('/participation/analytics/export?period=90&team_id='.$team->id)->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('Процент побед')->assertSee('50');
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
});
