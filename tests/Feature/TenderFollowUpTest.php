<?php

use App\Jobs\DeliverTelegramNotification;
use App\Jobs\RefreshWatchedTenders;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Models\TenderChange;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TelegramBotClient;
use App\Services\TenderFollowUpService;
use App\Services\TenderMatchingService;
use App\Services\TenderSourceImportService;
use App\Tenders\RostenderSearchTemplate;
use App\Tenders\RostenderTenderItem;
use App\Tenders\SourceFetchResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 9, 11)->setTime(7, 0));
});

function followUpFixture(): array
{
    $user = User::factory()->create(['telegram_id' => 'follow-up-user']);
    Entitlement::query()->create(['user_id' => $user->id, 'code' => 'active_queries', 'status' => 'active',
        'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10)]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Серверы', 'keywords' => ['сервер'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'rostender', 'external_id' => '101',
        'canonical_url' => 'https://rostender.info/tender/101', 'canonical_url_hash' => hash('sha256', '101'),
        'title' => 'Поставка серверов', 'budget_amount' => '1500000', 'deadline_at' => now()->addHours(72),
        'metadata' => ['rostender' => ['customer' => ['name' => 'Заказчик'], 'stage' => 'Подача заявок']]]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'match_reasons' => ['keywords' => ['сервер']], 'matched_at' => now()]);
    $state = TenderUserState::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'status' => 'favorite',
        'deadline_reminders_enabled' => true, 'action_reminder_enabled' => true, 'next_action_on' => now()->toDateString(),
        'watch_changes' => true, 'watch_started_at' => now()]);

    return [$user, $query, $tender, $state];
}

it('queues each deadline window and the local action date only once', function () {
    [$user, $query, $tender] = followUpFixture();
    $this->artisan('notifications:send-tender-reminders')->assertSuccessful();
    $this->artisan('notifications:send-tender-reminders')->assertSuccessful();
    expect(NotificationDelivery::query()->where('type', 'tender_deadline')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('type', 'tender_action')->count())->toBe(1);
    $this->travel(48)->hours();
    $this->artisan('notifications:send-tender-reminders')->assertSuccessful();
    expect(NotificationDelivery::query()->where('type', 'tender_deadline')->count())->toBe(2);
    $this->travel(25)->hours();
    $this->artisan('notifications:send-tender-reminders')->assertSuccessful();
    expect(NotificationDelivery::query()->where('type', 'tender_deadline')->count())->toBe(2);
});

it('uses the profile timezone and catches up actions after nine in the morning', function () {
    [$user] = followUpFixture();
    NotificationPreference::query()->create(['user_id' => $user->id, 'timezone' => 'America/New_York']);
    $this->artisan('notifications:send-tender-reminders');
    expect(NotificationDelivery::query()->where('type', 'tender_action')->count())->toBe(0);
    $this->travel(7)->hours();
    $this->artisan('notifications:send-tender-reminders');
    expect(NotificationDelivery::query()->where('type', 'tender_action')->count())->toBe(1);
});

it('does not remind for hidden cards paused queries or expired access', function (string $mode) {
    [$user, $query, $tender, $state] = followUpFixture();
    match ($mode) {
        'hidden' => $state->update(['status' => 'dismissed']),
        'paused' => $query->update(['status' => 'paused']),
        'expired' => Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()->subMinute()]),
    };
    $this->artisan('notifications:send-tender-reminders');
    expect(NotificationDelivery::query()->count())->toBe(0);
})->with(['hidden', 'paused', 'expired']);

it('skips a queued reminder when the deadline changes or the user opts out', function (string $mode) {
    [$user, $query, $tender, $state] = followUpFixture();
    app(TenderFollowUpService::class)->queueReminders();
    $delivery = NotificationDelivery::query()->where('type', 'tender_deadline')->sole();
    if ($mode === 'deadline') {
        $tender->update(['deadline_at' => now()->addDays(8)]);
    } else {
        $state->update(['deadline_reminders_enabled' => false]);
    }
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldNotReceive('sendMessage');
    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));
    expect($delivery->refresh()->status->value)->toBe('skipped');
})->with(['deadline', 'opt_out']);

it('delivers a valid reminder and includes the actual deadline', function () {
    followUpFixture();
    app(TenderFollowUpService::class)->queueReminders();
    $delivery = NotificationDelivery::query()->where('type', 'tender_deadline')->sole();
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldReceive('sendMessage')->once()->with('follow-up-user', Mockery::on(fn ($text) => str_contains($text, '14.09.2026') && str_contains($text, 'Поставка серверов')));
    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));
    expect($delivery->refresh()->status->value)->toBe('sent');
});

it('keeps follow-up choices when changing status and denies foreign state updates', function () {
    [$user, $query, $tender, $state] = followUpFixture();
    $this->actingAs($user)->patchJson('/tenders/'.$tender->id.'/state', ['status' => 'new'])
        ->assertOk()->assertJsonPath('state.watch_changes', true);
    expect($state->fresh())->not->toBeNull();
    $this->actingAs(User::factory()->create())->patchJson('/tenders/'.$tender->id.'/state', ['status' => 'favorite'])
        ->assertNotFound();
});

it('records a dismissal without changing filters until explicitly confirmed', function () {
    [$user, $query, $tender] = followUpFixture();
    $data = ['search_query_id' => $query->id, 'reason' => 'Не наша специализация', 'exclusion_kind' => 'keyword', 'exclusion_value' => 'сервер', 'apply_filter' => false];
    $this->actingAs($user)->postJson('/tenders/'.$tender->id.'/feedback', $data)->assertOk()->assertJsonPath('applied', false);
    expect($query->fresh()->minus_keywords)->toBeNull()
        ->and(DB::table('tender_feedback')->count())->toBe(1);
    $this->postJson('/tenders/'.$tender->id.'/feedback', [...$data, 'apply_filter' => true])->assertOk();
    expect($query->fresh()->minus_keywords)->toBe(['сервер'])
        ->and(app(TenderMatchingService::class)->evaluate($query->fresh(), $tender)->matches)->toBeFalse();
});

it('excludes the actual customer and preserves the exclusion when editing the monitoring', function () {
    [$user, $query, $tender] = followUpFixture();
    $this->actingAs($user)->postJson('/tenders/'.$tender->id.'/feedback', [
        'search_query_id' => $query->id, 'reason' => 'Не работаем с заказчиком', 'exclusion_kind' => 'customer',
        'exclusion_value' => 'Подменённый заказчик', 'apply_filter' => true,
    ])->assertOk();
    expect($query->fresh()->filters['excluded_customers'])->toBe(['Заказчик']);
    $this->patchJson('/queries/'.$query->id, ['keywords' => ['сервер'], 'filters' => ['relevance' => ['match_mode' => 'all']]])->assertOk();
    expect(app(TenderMatchingService::class)->evaluate($query->fresh(), $tender)->matches)->toBeFalse();
});

it('rejects feedback and change history access for another users tender', function () {
    [$user, $query, $tender] = followUpFixture();
    $this->actingAs(User::factory()->create())->postJson('/tenders/'.$tender->id.'/feedback', [
        'search_query_id' => $query->id, 'reason' => 'Тест', 'apply_filter' => false,
    ])->assertNotFound();
    $this->getJson('/tenders/'.$tender->id.'/changes')->assertNotFound();
    expect(DB::table('tender_feedback')->count())->toBe(0);
});

function followUpSourceItem(string $deadline = '2026-09-14 07:00:00', int $budget = 1500000, string $stage = 'Подача заявок'): RostenderTenderItem
{
    return RostenderTenderItem::fromDetail(['id' => 101, 'descr' => 'Поставка серверов', 'dte' => $deadline,
        'price' => ['value' => $budget, 'currency' => 'RUB'], 'stage' => $stage, 'customer' => ['name' => 'Заказчик']]);
}

function followUpFeed(): SourceFeed
{
    return SourceFeed::query()->create(['source' => 'rostender', 'source_identifier' => 42,
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/42', 'url_hash' => hash('sha256', 'followup-feed'),
        'status' => 'active', 'poll_interval_seconds' => 3600, 'next_poll_at' => now()->addHour()]);
}

it('records actual changes once without treating initial or partial imports as changes', function () {
    [$user, $query, $tender] = followUpFixture();
    $feed = followUpFeed();
    $importer = app(TenderSourceImportService::class);
    $item = followUpSourceItem('2026-09-16 12:00:00', 1800000, 'Работа комиссии');
    $importer->import($feed, new SourceFetchResult([$item]), 'rostender', false);
    $importer->import($feed, new SourceFetchResult([$item]), 'rostender', false);
    expect(TenderChange::query()->count())->toBe(1)
        ->and(TenderChange::query()->sole()->changes)->toHaveKeys(['deadline_at', 'budget_amount', 'stage'])
        ->and(NotificationDelivery::query()->where('type', 'tender_change')->count())->toBe(1);
    $this->actingAs($user)->getJson('/tenders/'.$tender->id.'/changes')->assertOk()->assertJsonCount(1, 'changes');
    $partial = RostenderTenderItem::fromDetail(['id' => 101, 'descr' => 'Поставка серверов']);
    $importer->import($feed, new SourceFetchResult([$partial]), 'rostender', false);
    expect(TenderChange::query()->count())->toBe(1)
        ->and($tender->fresh()->budget_amount)->toBe('1800000.00');
});

it('rolls back change history and notifications together with the imported update', function () {
    followUpFixture();
    $feed = followUpFeed();
    DB::beginTransaction();
    app(TenderSourceImportService::class)->import($feed, new SourceFetchResult([followUpSourceItem(budget: 2000000)]), 'rostender', false);
    DB::rollBack();
    expect(TenderChange::query()->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});

it('refreshes watched cards outside the template page without moving the normal poll time', function () {
    followUpFixture();
    $feed = followUpFeed();
    app(TenderSourceImportService::class)->import($feed, new SourceFetchResult([followUpSourceItem()]), 'rostender', false);
    $next = $feed->fresh()->next_poll_at->toAtomString();
    config()->set(['tender.rostender.enabled' => true, 'tender.rostender.public_distribution_approved' => true, 'tender.rostender.api_key' => 'test']);
    Http::fake(['https://rostender.info/api/tenders/get/101' => Http::response(['success' => true, 'data' => [
        'id' => 101, 'descr' => 'Поставка серверов', 'price' => ['value' => 2500000, 'currency' => 'RUB'],
    ]])]);
    app()->call([(new RefreshWatchedTenders), 'handle']);
    app()->call([(new RefreshWatchedTenders), 'handle']);
    Http::assertSentCount(1);
    expect($feed->fresh()->next_poll_at->toAtomString())->toBe($next)
        ->and(Tender::query()->sole()->budget_amount)->toBe('2500000.00');
});

it('stops watched refresh when the shared RosTender quota is exhausted', function () {
    followUpFixture();
    $feed = followUpFeed();
    app(TenderSourceImportService::class)->import($feed, new SourceFetchResult([followUpSourceItem()]), 'rostender', false);
    config()->set(['tender.rostender.enabled' => true, 'tender.rostender.public_distribution_approved' => true,
        'tender.rostender.api_key' => 'test', 'tender.rostender.daily_quota_limit' => 0]);
    Http::fake();
    app()->call([(new RefreshWatchedTenders), 'handle']);
    Http::assertNothingSent();
});

it('previews EIS without saving queries importing cards or queuing notifications', function () {
    [$user] = followUpFixture();
    Http::fake(['zakupki.gov.ru/*' => Http::response(file_get_contents(base_path('tests/Fixtures/eis-rss-initial.xml')), 200, ['Content-Type' => 'application/rss+xml'])]);
    $this->actingAs($user)->postJson('/queries/preview', ['keywords' => ['неподходящее слово']])->assertOk()
        ->assertJsonPath('checked', 1)->assertJsonPath('matched', 0)->assertJsonPath('excluded.keyword', 1);
    expect(SearchQuery::query()->count())->toBe(1)->and(Tender::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('distinguishes unavailable preview from a successful empty result and requires active access', function () {
    [$user] = followUpFixture();
    Http::fake(['zakupki.gov.ru/*' => Http::response('', 503)]);
    $this->actingAs($user)->postJson('/queries/preview', ['keywords' => ['сервер']])->assertStatus(503)->assertJsonMissingPath('matched');
    $this->actingAs(User::factory()->create())->postJson('/queries/preview', ['keywords' => ['сервер']])->assertForbidden();
});

it('previews only the selected RosTender template with a bounded cached sample', function () {
    [$user] = followUpFixture();
    config()->set(['tender.rostender.enabled' => true, 'tender.rostender.public_distribution_approved' => true, 'tender.rostender.api_key' => 'test']);
    Cache::put('rostender:search-templates:v1', [new RostenderSearchTemplate(42, 'Серверы')], 60);
    Http::fake([
        'https://rostender.info/api/tenders/get/template/42*' => Http::response(['success' => true, 'data' => array_map(fn ($id) => ['id' => $id], range(101, 110)), '_meta' => ['totalCount' => 10, 'pageCount' => 1]]),
        'https://rostender.info/api/tenders/get/*' => fn ($request) => Http::response(['success' => true, 'data' => ['id' => (int) basename($request->url()), 'descr' => 'Поставка серверов', 'price' => ['value' => 1500000]]]),
    ]);
    $payload = ['keywords' => ['сервер'], 'budget_min' => 1000000, 'filters' => ['source' => ['rostender_template_id' => 42]]];
    $this->actingAs($user)->postJson('/queries/preview', $payload)->assertOk()->assertJsonPath('checked', 5)->assertJsonPath('matched', 5);
    $this->postJson('/queries/preview', [...$payload, 'budget_min' => 2000000])->assertOk()->assertJsonPath('excluded.budget', 5);
    Http::assertSentCount(6);
    $this->postJson('/queries/preview', [...$payload, 'filters' => ['source' => ['rostender_template_id' => 999]]])->assertUnprocessable();
    Http::assertSentCount(6);
});

it('sends a change notification with before and after and skips it after opting out', function () {
    [$user, $query, $tender, $state] = followUpFixture();
    $feed = followUpFeed();
    app(TenderSourceImportService::class)->import($feed, new SourceFetchResult([followUpSourceItem(budget: 2000000)]), 'rostender', false);
    $delivery = NotificationDelivery::query()->where('type', 'tender_change')->sole();
    $bot = Mockery::mock(TelegramBotClient::class);
    $bot->shouldReceive('sendMessage')->once()->with('follow-up-user', Mockery::on(fn ($text) => str_contains($text, '1500000.00 → 2000000.00')));
    (new DeliverTelegramNotification($delivery->id))->handle($bot, app(AccessService::class));
    expect($delivery->refresh()->status->value)->toBe('sent');
    app(TenderSourceImportService::class)->import($feed, new SourceFetchResult([followUpSourceItem(budget: 3000000)]), 'rostender', false);
    $state->update(['watch_changes' => false]);
    $pending = NotificationDelivery::query()->where('type', 'tender_change')->latest('id')->first();
    (new DeliverTelegramNotification($pending->id))->handle($bot, app(AccessService::class));
    expect($pending->refresh()->status->value)->toBe('skipped');
});

it('does not notify about the first source import or a repeated identical update', function () {
    $feed = followUpFeed();
    $item = followUpSourceItem();
    app(TenderSourceImportService::class)->import($feed, new SourceFetchResult([$item]), 'rostender', false);
    app(TenderSourceImportService::class)->import($feed, new SourceFetchResult([$item]), 'rostender', false);
    expect(TenderChange::query()->count())->toBe(0)->and(NotificationDelivery::query()->count())->toBe(0);
});
