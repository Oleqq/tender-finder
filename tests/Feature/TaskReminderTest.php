<?php

use App\Jobs\DeliverTelegramNotification;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\TenderParticipation;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TaskReminderService;
use App\Services\TelegramBotClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/team-workspace.php';

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 9, 13)->setTime(6, 0));
});

function reminderFixture(): array
{
    [$owner, $member, $viewer, $team, $tender] = teamFixture();
    $member->update(['telegram_id' => 'task-recipient']);
    Entitlement::query()->create(['user_id' => $member->id, 'code' => 'active_queries', 'status' => 'active', 'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(30)]);
    NotificationPreference::query()->create(['user_id' => $member->id, 'timezone' => 'Europe/Moscow']);
    $p = TenderParticipation::query()->create(['user_id' => $owner->id, 'team_id' => $team->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    $item = $p->items()->create(['title' => 'Проверить заявку', 'due_on' => '2026-09-14', 'assignee_id' => $member->id, 'reminder_enabled' => true]);

    return [$member, $team, $p, $item];
}

it('queues one upcoming and one overdue reminder after 9 in the recipients timezone', function () {
    [$member, $team, $p, $item] = reminderFixture();
    $service = app(TaskReminderService::class);
    $this->travelBack();
    $this->travelTo(now()->setDate(2026, 9, 13)->setTime(5, 59));
    $service->queueDue();
    expect(NotificationDelivery::query()->count())->toBe(0);
    $this->travel(1)->minutes();
    $service->queueDue();
    $service->queueDue();
    expect(NotificationDelivery::query()->count())->toBe(1)->and(NotificationDelivery::query()->sole()->user_id)->toBe($member->id);
    $this->travel(1)->days();
    $service->queueDue();
    expect(NotificationDelivery::query()->count())->toBe(1);
    $this->travel(1)->days();
    $service->queueDue();
    $service->queueDue();
    expect(NotificationDelivery::query()->count())->toBe(2);
    $this->travel(2)->days();
    $service->queueDue();
    expect(NotificationDelivery::query()->count())->toBe(2);
    Queue::assertPushed(DeliverTelegramNotification::class, 2);
});

it('delivers an assigned task with a team link and does not send a queued message twice', function () {
    [$member, $team, $p, $item] = reminderFixture();
    app(TaskReminderService::class)->queueDue();
    $delivery = NotificationDelivery::query()->sole();
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldReceive('sendMessage')->once()->with('task-recipient', Mockery::on(fn ($text) => str_contains($text, 'Задача на завтра') && str_contains($text, 'team_id='.$team->id)));
    $job = new DeliverTelegramNotification($delivery->id);
    $job->handle($bot, app(AccessService::class));
    $job->handle($bot, app(AccessService::class));
    expect($delivery->fresh()->status->value)->toBe('sent');
});

it('revalidates completion deadline assignment opt out membership and access at delivery', function (string $change) {
    [$member, $team, $p, $item] = reminderFixture();
    app(TaskReminderService::class)->queueDue();
    $delivery = NotificationDelivery::query()->sole();
    match ($change) {
        'complete' => $item->update(['completed_at' => now()]),
        'date' => $item->update(['due_on' => '2026-09-20']),
        'assignee' => $item->update(['assignee_id' => null]),
        'optout' => $item->update(['reminder_enabled' => false]),
        'member' => DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->delete(),
        'access' => Entitlement::query()->where('user_id', $member->id)->update(['ends_at' => now()->subMinute()]),
        'deleted' => $item->delete(),
        'finished' => $p->update(['stage' => 'won']),
    };
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldNotReceive('sendMessage');
    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));
    expect($delivery->fresh()->status->value)->toBe('skipped');
})->with(['complete', 'date', 'assignee', 'optout', 'member', 'access', 'deleted', 'finished']);

it('requires a deadline when enabling a reminder and never assigns a task to outsiders', function () {
    [$member, $team, $p, $item] = reminderFixture();
    $root = '/tenders/'.$p->tender_id.'/checklist';
    $scope = '?team_id='.$team->id;
    $this->actingAs($member)->postJson($root.$scope, ['title' => 'Без даты', 'reminder_enabled' => true])->assertUnprocessable();
    $this->postJson($root.$scope, ['title' => 'Чужой', 'assignee_id' => 999])->assertUnprocessable();
});

it('retries failed task deliveries and clears failure metadata after success', function () {
    reminderFixture();
    app(TaskReminderService::class)->queueDue();
    $delivery = NotificationDelivery::query()->sole();
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldReceive('sendMessage')->once()->andThrow(new RuntimeException('Temporary failure'));
    $job = new DeliverTelegramNotification($delivery->id);
    expect(fn () => $job->handle($bot, app(AccessService::class)))->toThrow(RuntimeException::class);
    expect($delivery->fresh()->status->value)->toBe('failed');
    $retryBot = Mockery::mock(TelegramBotClient::class);
    $retryBot->shouldReceive('sendMessage')->once();
    $job->handle($retryBot, app(AccessService::class));
    expect($delivery->fresh()->status->value)->toBe('sent')->and($delivery->fresh()->failure_code)->toBeNull();
});

it('reminds the owner of a personal task and stops after the tender is hidden', function () {
    [$member, $team, $p, $item] = reminderFixture();
    $owner = User::query()->findOrFail($p->user_id);
    $owner->update(['telegram_id' => 'personal-recipient']);
    Entitlement::query()->create(['user_id' => $owner->id, 'code' => 'active_queries', 'status' => 'active', 'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(30)]);
    $personal = TenderParticipation::query()->create(['user_id' => $owner->id, 'tender_id' => $p->tender_id, 'stage' => 'preparing']);
    $task = $personal->items()->create(['title' => 'Личная задача', 'due_on' => '2026-09-14', 'reminder_enabled' => true]);
    $service = app(TaskReminderService::class);
    expect($service->recipient($task)->id)->toBe($owner->id);
    TenderUserState::query()->create(['user_id' => $owner->id, 'tender_id' => $p->tender_id, 'status' => 'archived']);
    expect($service->recipient($task))->toBeNull();
    $item->update(['assignee_id' => null]);
    $service->queueDue();
    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('does not queue or deliver reminders from an archived team', function () {
    [$member, $team, $p, $item] = reminderFixture();
    $team->update(['archived_at' => now()]);

    expect(app(TaskReminderService::class)->recipient($item))->toBeNull();
    app(TaskReminderService::class)->queueDue();
    expect(NotificationDelivery::query()->count())->toBe(0);
});
