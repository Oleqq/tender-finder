<?php

use App\Enums\UserRole;
use App\Jobs\CheckRepeatTenders;
use App\Models\Entitlement;
use App\Models\RepeatTenderCandidate;
use App\Models\RepeatTenderWatch;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\RepeatTenderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.local_mvp_full_access.enabled', false);
});

function repeatTenderUser(string $plan = 'ultra'): User
{
    $user = User::factory()->create();
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->byCode($plan)->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 30, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

    return $user;
}

function repeatTenderCard(User $user, array $fields = []): Tender
{
    $id = (string) Str::uuid();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Мебель', 'keywords' => ['мебель'], 'status' => 'active']);
    $t = Tender::query()->create([...['source' => 'workspace_ru', 'external_id' => $id, 'title' => 'Поставка офисной мебели',
        'canonical_url' => 'https://example.test/'.$id, 'canonical_url_hash' => hash('sha256', $id), 'reg_number' => str_replace('-', '', $id),
        'metadata' => ['customer' => 'Учебный заказчик'], 'description' => 'Столы и стулья. Доставка за 10 дней.', 'budget_amount' => '100000', 'currency' => 'RUB',
        'published_at' => '2026-10-20 12:00:00', 'deadline_at' => '2026-11-02 12:00:00'], ...$fields]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $t->id, 'matched_at' => now(), 'match_reasons' => []]);

    return $t;
}

function repeatTenderWatch(User $user): RepeatTenderWatch
{
    $t = repeatTenderCard($user);

    return RepeatTenderWatch::query()->create(['user_id' => $user->id, 'tender_id' => $t->id, 'reason' => 'Срок поставки слишком короткий',
        'original' => app(RepeatTenderService::class)->snapshot($t), 'version' => 1, 'scan_after' => now()]);
}

function repeatTenderConfirmation(RepeatTenderCandidate $c): array
{
    return ['version' => $c->version, 'watch_version' => RepeatTenderWatch::query()->findOrFail($c->watch_id)->version, 'confirmed' => true, 'latest_hash' => app(RepeatTenderService::class)->fingerprint(app(RepeatTenderService::class)->snapshot(Tender::query()->findOrFail($c->tender_id))),
        'source_url' => 'https://example.test/repeat-protocol', 'source_reference' => 'Уведомление заказчика №2, п.3',
        'quote' => 'После отмены прежней процедуры объявлена новая закупка с изменённым сроком поставки.', 'checked_on' => '2026-10-29',
        'previous_outcome' => 'Отменена заказчиком, уведомление №1', 'note' => 'В уведомлении указаны номера обоих извещений. Сверены оригиналы.'];
}

it('gates all data and direct operations for lower preview and expired tiers', function (string $plan) {
    $owner = repeatTenderUser();
    $watch = repeatTenderWatch($owner);
    $user = $plan === 'preview' ? User::factory()->create() : repeatTenderUser($plan === 'expired' ? 'ultra' : $plan);
    if ($plan === 'expired') {
        Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()]);
    }
    $this->actingAs($user)->get('/repeat-tenders?from='.$watch->tender_id)->assertInertia(fn (Assert $p) => $p->component('RepeatTenders')->where('enabled', false)->where('watches', null)->where('from', null)->where('unread', 0));
    $this->get('/repeat-tenders/'.$watch->id)->assertForbidden();
    $this->getJson('/repeat-tenders/lookup?search=мебели')->assertForbidden();
    $this->postJson('/repeat-tenders', [])->assertForbidden();
    $this->patchJson('/repeat-tenders/'.$watch->id, [])->assertForbidden();
    $this->patchJson('/repeat-tenders/'.$watch->id.'/archive', [])->assertForbidden();
    $this->postJson('/repeat-tenders/'.$watch->id.'/scan', [])->assertForbidden();
    $this->postJson('/repeat-tenders/'.$watch->id.'/candidates', [])->assertForbidden();
    foreach (['confirm', 'dismiss', 'read'] as $decision) {
        $this->postJson('/repeat-tenders/'.$watch->id.'/candidates/1/'.$decision, [])->assertForbidden();
    }
})->with(['basic', 'pro', 'expired', 'preview']);

it('captures authoritative originals requires refusal reason and handles duplicate creation and stale edits', function () {
    $user = repeatTenderUser();
    $card = repeatTenderCard($user);
    $this->actingAs($user)->postJson('/repeat-tenders', ['tender_id' => $card->id, 'reason' => '', 'confirmed' => true])->assertUnprocessable();
    $data = ['tender_id' => $card->id, 'reason' => 'Не успеваем поставить', 'confirmed' => true, 'user_id' => 999, 'original' => ['budget_amount' => '999999']];
    $id = $this->postJson('/repeat-tenders', $data)->assertOk()->json('id');
    $this->postJson('/repeat-tenders', $data)->assertOk()->assertJsonPath('id', $id);
    $this->get('/repeat-tenders?from='.$card->id)->assertRedirect('/repeat-tenders/'.$id);
    $this->postJson('/repeat-tenders', [...$data, 'reason' => 'Другая причина'])->assertConflict();
    $this->patchJson('/repeat-tenders/'.$id, ['version' => 1, 'reason' => 'Слишком короткий срок', 'confirmed' => true])->assertOk();
    $this->patchJson('/repeat-tenders/'.$id, ['version' => 1, 'reason' => 'Старая вкладка', 'confirmed' => true])->assertConflict();
    $card->update(['budget_amount' => '150000', 'description' => 'Новое описание прежней карточки']);
    $this->get('/repeat-tenders/'.$id)->assertInertia(fn (Assert $p) => $p->where('watch.original.budget_amount', '100000.00')->where('watch.reason', 'Слишком короткий срок'));
    expect(RepeatTenderWatch::query()->count())->toBe(1)->and(RepeatTenderWatch::query()->findOrFail($id)->user_id)->toBe($user->id);
});

it('isolates users and personal scopes and supports case insensitive bounded lookup without wildcard expansion', function () {
    $a = repeatTenderUser();
    $b = repeatTenderUser();
    $watch = repeatTenderWatch($a);
    $foreign = repeatTenderCard($b);
    $this->actingAs($b)->get('/repeat-tenders/'.$watch->id)->assertNotFound();
    $this->get('/repeat-tenders?from='.$watch->tender_id)->assertNotFound();
    $this->postJson('/repeat-tenders', ['tender_id' => $watch->tender_id, 'reason' => 'Чужой', 'confirmed' => true])->assertNotFound();
    $this->actingAs($a)->getJson('/repeat-tenders/lookup?search='.rawurlencode('ОФИСНОЙ'))->assertOk()->assertJsonCount(1, 'tenders');
    $this->getJson('/repeat-tenders/lookup?search=%25%25')->assertJsonCount(0, 'tenders');
    $this->postJson('/repeat-tenders/'.$watch->id.'/candidates', ['tender_id' => $foreign->id])->assertNotFound();
    $this->postJson('/repeat-tenders/'.$watch->id.'/candidates?team_id=1', ['tender_id' => $foreign->id])->assertUnprocessable();
    $this->get('/repeat-tenders?team_id=1')->assertUnprocessable();
});

it('detects only preliminary distinct later notices for the same known customer and deduplicates alerts and jobs', function () {
    $user = repeatTenderUser();
    $watch = repeatTenderWatch($user);
    $service = app(RepeatTenderService::class);
    $new = repeatTenderCard($user, ['published_at' => '2026-10-28', 'budget_amount' => '120000']);
    repeatTenderCard($user, ['published_at' => '2026-10-28', 'metadata' => ['customer' => 'Другой заказчик']]);
    repeatTenderCard($user, ['published_at' => null]);
    repeatTenderCard($user, ['published_at' => '2026-10-30']);
    repeatTenderCard($user, ['published_at' => '2026-10-28', 'reg_number' => $watch->original['reg_number']]);
    repeatTenderCard($user, ['published_at' => '2026-10-28', 'deadline_at' => '2026-10-28']);
    repeatTenderCard($user, ['published_at' => '2026-10-28', 'title' => 'Поставка мебели']);
    repeatTenderCard($user, ['published_at' => '2026-10-28', 'metadata' => []]);
    $private = repeatTenderCard(repeatTenderUser(), ['published_at' => '2026-10-28']);
    expect($service->scanUser($user->id))->toBe(1)->and($service->scanUser($user->id))->toBe(0)->and($service->unread($user))->toBe(1);
    $c = RepeatTenderCandidate::query()->sole();
    expect($c->tender_id)->toBe($new->id)->and($c->status)->toBe('possible')->and($c->evidence)->toBeNull()->and($c->basis['terms'])->toHaveCount(2);
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('repeatAlerts', 1));
    $this->postJson('/repeat-tenders/'.$watch->id.'/candidates/'.$c->id.'/read', ['version' => 1, 'confirmed' => true])->assertOk();
    expect($service->unread($user))->toBe(0);
    $this->get('/repeat-tenders?unread=1')->assertInertia(fn (Assert $p) => $p->has('watches.data', 0));
    (new CheckRepeatTenders($user->id))->handle($service);
    expect(RepeatTenderCandidate::query()->count())->toBe(1)->and($service->unread($user))->toBe(0);
});

it('confirms only with cited manual proof and fresh cards freezes comparisons and preserves unknown values', function () {
    $user = repeatTenderUser();
    $watch = repeatTenderWatch($user);
    $new = repeatTenderCard($user, ['published_at' => '2026-10-28', 'budget_amount' => null, 'deadline_at' => null, 'description' => 'Доставка за 30 дней.']);
    app(RepeatTenderService::class)->scanUser($user->id);
    $c = RepeatTenderCandidate::query()->sole();
    $root = '/repeat-tenders/'.$watch->id.'/candidates/'.$c->id;
    $data = repeatTenderConfirmation($c);
    $this->actingAs($user)->postJson($root.'/confirm', [...$data, 'confirmed' => false])->assertUnprocessable();
    $this->postJson($root.'/confirm', [...$data, 'source_url' => 'http://example.test'])->assertUnprocessable();
    $this->postJson($root.'/confirm', [...$data, 'quote' => ''])->assertUnprocessable();
    $this->postJson($root.'/confirm', [...$data, 'checked_on' => '2026-10-30'])->assertUnprocessable();
    $watch->update(['reason' => 'Уточнённая причина', 'version' => 2]);
    $this->postJson($root.'/confirm', $data)->assertConflict();
    $data = repeatTenderConfirmation($c);
    $new->update(['region' => 'Москва']);
    $this->postJson($root.'/confirm', $data)->assertConflict();
    $this->postJson($root.'/confirm', repeatTenderConfirmation($c))->assertOk();
    $confirmed = $c->fresh();
    expect($confirmed->status)->toBe('confirmed')->and($confirmed->snapshot['budget_amount'])->toBeNull()->and($confirmed->snapshot['deadline_at'])->toBeNull()
        ->and($confirmed->evidence['reason_at_confirmation'])->toBe($watch->reason);
    $new->update(['budget_amount' => '200000']);
    $this->get('/repeat-tenders/'.$watch->id)->assertInertia(fn (Assert $p) => $p->where('candidates.data.0.changed', true)->where('candidates.data.0.snapshot.budget_amount', null)->where('candidates.data.0.latest.budget_amount', '200000.00'));
    $this->postJson($root.'/confirm', repeatTenderConfirmation($confirmed))->assertConflict();
    $this->postJson($root.'/dismiss', ['version' => 2, 'note' => 'В источнике исправлена связь', 'confirmed' => true])->assertOk();
    expect($c->fresh()->evidence)->toBe($confirmed->evidence)->and($c->fresh()->snapshot)->toBe($confirmed->snapshot);
    app(RepeatTenderService::class)->scanUser($user->id);
    expect($c->fresh()->status)->toBe('dismissed')->and(app(RepeatTenderService::class)->unread($user))->toBe(0);
});

it('supports manual unknown-customer links without pretending they are confirmed and rejects mirror notices', function () {
    $user = repeatTenderUser();
    $watch = repeatTenderWatch($user);
    $new = repeatTenderCard($user, ['metadata' => [], 'published_at' => null]);
    $this->actingAs($user)->postJson('/repeat-tenders/'.$watch->id.'/candidates', ['tender_id' => $watch->tender_id])->assertUnprocessable();
    $this->postJson('/repeat-tenders/'.$watch->id.'/candidates', ['tender_id' => $new->id])->assertOk();
    $this->postJson('/repeat-tenders/'.$watch->id.'/candidates', ['tender_id' => $new->id])->assertOk();
    $c = RepeatTenderCandidate::query()->sole();
    expect($c->basis['kind'])->toBe('manual')->and($c->status)->toBe('possible')->and($c->evidence)->toBeNull();
    $otherWatch = repeatTenderWatch($user);
    $this->postJson('/repeat-tenders/'.$otherWatch->id.'/candidates/'.$c->id.'/read', ['version' => 1, 'confirmed' => true])->assertNotFound();
});

it('blocks archived deleted-source and expired mutations and skips inactive monitoring periods', function () {
    $user = repeatTenderUser();
    $watch = repeatTenderWatch($user);
    $service = app(RepeatTenderService::class);
    $root = '/repeat-tenders/'.$watch->id;
    $this->actingAs($user)->patchJson($root.'/archive', ['version' => 1, 'archived' => true, 'confirmed' => true])->assertOk();
    $new = repeatTenderCard($user, ['published_at' => '2026-10-28']);
    expect($service->scanUser($user->id))->toBe(0);
    $this->postJson($root.'/scan')->assertConflict();
    $this->postJson($root.'/candidates', ['tender_id' => $new->id])->assertConflict();
    $this->get('/repeat-tenders?archived=1')->assertInertia(fn (Assert $p) => $p->has('watches.data', 1));
    $this->patchJson($root.'/archive', ['version' => 2, 'archived' => false, 'confirmed' => true])->assertOk();
    $p = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $watch->tender_id, 'stage' => 'lost']);
    $p->delete();
    $this->postJson($root.'/scan')->assertConflict();
    $this->get($root)->assertInertia(fn (Assert $page) => $page->where('watch.available', false));
    $p->restore();
    $this->travel(1)->days();
    expect($service->scanUser($user->id))->toBe(0);
    $this->postJson($root.'/scan')->assertForbidden();
    Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()->addDay()]);
    expect($service->scanUser($user->id))->toBe(0)->and(RepeatTenderCandidate::query()->count())->toBe(0);
});

it('follows selected developer plan and queues one background check per owner without external messages', function () {
    $user = repeatTenderUser();
    repeatTenderWatch($user);
    repeatTenderWatch($user);
    $user->forceFill(['role' => UserRole::SuperAdmin, 'developer_plan_code' => 'basic'])->save();
    $this->actingAs($user)->get('/repeat-tenders')->assertInertia(fn (Assert $p) => $p->where('enabled', false));
    $user->forceFill(['developer_plan_code' => 'ultra'])->save();
    $this->actingAs($user->fresh())->get('/repeat-tenders')->assertInertia(fn (Assert $p) => $p->where('enabled', true)->has('watches.data', 2));
    Bus::fake();
    $this->artisan('tenders:check-repeats')->assertSuccessful();
    Bus::assertDispatchedTimes(CheckRepeatTenders::class, 1);
    Bus::assertDispatched(CheckRepeatTenders::class, fn ($job) => $job->userId === $user->id);
});

it('enforces active watch capacity and paginates the preserved archive', function () {
    $user = repeatTenderUser();
    $sample = repeatTenderWatch($user);
    for ($i = 0; $i < 99; $i++) {
        $tender = repeatTenderCard($user);
        RepeatTenderWatch::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'reason' => 'Не подходит срок',
            'original' => app(RepeatTenderService::class)->snapshot($tender), 'version' => 1, 'scan_after' => now()]);
    }
    $next = repeatTenderCard($user);
    $this->actingAs($user)->get('/repeat-tenders')->assertInertia(fn (Assert $p) => $p->has('watches.data', 10)->where('watches.total', 100));
    $payload = ['tender_id' => $next->id, 'reason' => 'Не подходит цена', 'confirmed' => true];
    $this->postJson('/repeat-tenders', $payload)->assertUnprocessable();
    $this->patchJson('/repeat-tenders/'.$sample->id.'/archive', ['version' => 1, 'archived' => true, 'confirmed' => true])->assertOk();
    $this->postJson('/repeat-tenders', $payload)->assertOk();
    $this->patchJson('/repeat-tenders/'.$sample->id.'/archive', ['version' => 2, 'archived' => false, 'confirmed' => true])->assertUnprocessable();
    $this->get('/repeat-tenders?archived=1')->assertInertia(fn (Assert $p) => $p->has('watches.data', 1));
});

it('bounds pending candidates preserves dismissed proofs and tells users when scanning was limited', function () {
    $user = repeatTenderUser();
    $watch = repeatTenderWatch($user);
    $service = app(RepeatTenderService::class);
    for ($i = 0; $i < 101; $i++) {
        repeatTenderCard($user, ['published_at' => '2026-10-28']);
    }
    expect($service->scanUser($user->id))->toBe(100)->and(RepeatTenderCandidate::query()->count())->toBe(100)->and($watch->fresh()->scan_limited)->toBeTrue();
    $c = RepeatTenderCandidate::query()->first();
    $this->actingAs($user)->postJson('/repeat-tenders/'.$watch->id.'/candidates/'.$c->id.'/dismiss', ['version' => 1, 'note' => 'Другая комплектация', 'confirmed' => true])->assertOk();
    expect($service->scanUser($user->id))->toBe(1)->and($c->fresh()->status)->toBe('dismissed');
    $this->get('/repeat-tenders/'.$watch->id)->assertInertia(fn (Assert $p) => $p->has('candidates.data', 10)->where('candidates.total', 101));
});
