<?php

use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\ProcurementComparison;
use App\Models\ProcurementNeed;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\ProcurementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.local_mvp_full_access.enabled', false);
});

function procurementUser(string $plan = 'ultra'): User
{
    $user = User::factory()->create();
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->byCode($plan)->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 30, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

    return $user;
}

function procurementContract(User $user, ?Team $team = null): TenderParticipation
{
    $id = (string) Str::uuid();
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Мои закупки', 'keywords' => ['детали'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => $id, 'title' => 'Контракт '.$id,
        'canonical_url' => 'https://example.test/'.$id, 'canonical_url_hash' => hash('sha256', $id)]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);

    return TenderParticipation::query()->create(['user_id' => $user->id, 'team_id' => $team?->id, 'tender_id' => $tender->id, 'stage' => 'won']);
}

function procurementNeedPayload(TenderParticipation $p, string $quantity = '40'): array
{
    return ['participation_id' => $p->id, 'version' => 0, 'title' => 'Крепёж М8', 'specification' => 'Оцинкованный, класс 8.8',
        'unit' => 'шт', 'quantity' => $quantity, 'required_on' => '2026-11-10'];
}

function procurementPair(User $user, ?Team $team = null): array
{
    $needs = [];
    foreach (['40', '60'] as $quantity) {
        $p = procurementContract($user, $team);
        $data = procurementNeedPayload($p, $quantity);
        $needs[] = ProcurementNeed::query()->create([...$data, 'version' => 1, 'match_key' => app(ProcurementService::class)->matchKey($data['title'])]);
    }

    return $needs;
}

function procurementSelection(array $needs): array
{
    return ['needs' => array_map(fn (ProcurementNeed $need): array => ['id' => $need->id, 'version' => $need->version], $needs)];
}

function procurementQuotes(array $needs, int $version = 1): array
{
    $offer = ['supplier' => 'Поставщик', 'reference' => 'Письменное КП №42', 'quoted_on' => '2026-10-28', 'valid_until' => '2026-11-08',
        'unit_price' => '100.00', 'delivery_cost' => '500.00', 'delivery_on' => '2026-11-05'];

    return ['version' => $version, 'conditions_note' => 'Одинаковая комплектация, цены с НДС. Совместимость и даты проверены.',
        'offers' => array_map(fn (ProcurementNeed $need): array => ['need_id' => $need->id, ...$offer], $needs),
        'combined_offer' => [...$offer, 'unit_price' => '90.00', 'delivery_cost' => '600.00']];
}

it('gates Ultra data and all direct mutations and follows the selected owner developer tier', function (string $plan) {
    $user = procurementUser($plan);
    $p = procurementContract($user);
    $needs = procurementPair($user);
    $this->actingAs($user)->get('/procurement')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Procurement')
        ->where('enabled', false)->has('needs', 0)->has('contracts', 0)->has('candidates', 0)->has('comparisons.data', 0));
    $this->postJson('/procurement/needs', procurementNeedPayload($p))->assertForbidden();
    $this->patchJson('/procurement/needs/'.$needs[0]->id, [...procurementNeedPayload($p), 'version' => 1])->assertForbidden();
    $this->deleteJson('/procurement/needs/'.$needs[0]->id, ['version' => 1])->assertForbidden();
    $this->postJson('/procurement/comparisons', procurementSelection($needs))->assertForbidden();
    $user->forceFill(['role' => UserRole::SuperAdmin, 'developer_plan_code' => 'ultra'])->save();
    $this->actingAs($user->fresh())->get('/procurement')->assertInertia(fn (Assert $page) => $page->where('enabled', true));
    $user->forceFill(['developer_plan_code' => 'basic'])->save();
    $this->actingAs($user->fresh())->postJson('/procurement/comparisons', procurementSelection($needs))->assertForbidden();
})->with(['basic', 'pro']);

it('edits owned needs with versions normalizes quantities and proposes only preliminary scoped matches', function () {
    $user = procurementUser();
    $p = procurementContract($user);
    $p2 = procurementContract($user);
    $other = procurementUser();
    procurementPair($other);
    $this->actingAs($user);
    foreach (['0', '-1', '1.0001', '100000', 'abc'] as $quantity) {
        $this->postJson('/procurement/needs', procurementNeedPayload($p, $quantity))->assertUnprocessable();
    }
    $id = $this->postJson('/procurement/needs', procurementNeedPayload($p, '40,125'))->assertOk()->json('id');
    $id2 = $this->postJson('/procurement/needs', [...procurementNeedPayload($p2, '59.875'), 'title' => 'КРЕПЁЖ М8', 'specification' => 'Другая модель', 'required_on' => '2026-11-12'])->assertOk()->json('id');
    $this->get('/procurement')->assertInertia(fn (Assert $page) => $page->has('needs', 2)->has('candidates', 1)
        ->where('needs.0.quantity', '40.125')->where('candidates.0.quantity', '100.000')->where('candidates.0.different_specs', true)->where('candidates.0.different_dates', true));
    $this->patchJson('/procurement/needs/'.$id, [...procurementNeedPayload($p), 'version' => 0])->assertConflict();
    $this->patchJson('/procurement/needs/'.$id, [...procurementNeedPayload($p), 'version' => 1, 'participation_id' => $p2->id])->assertOk();
    expect(ProcurementNeed::query()->findOrFail($id)->participation_id)->toBe($p->id);
    $this->deleteJson('/procurement/needs/'.$id, ['version' => 1])->assertConflict();
    $this->deleteJson('/procurement/needs/'.$id, ['version' => 2])->assertOk();
    $this->actingAs($other)->deleteJson('/procurement/needs/'.$id2, ['version' => 1])->assertNotFound();
});

it('compares 40 and 60 units with actual supplier quotes and delivery and allocates 1400 rubles exactly once', function () {
    $user = procurementUser();
    $needs = procurementPair($user);
    $this->actingAs($user);
    $id = $this->postJson('/procurement/comparisons', procurementSelection($needs))->assertOk()->json('id');
    $root = '/procurement/comparisons/'.$id;
    $this->get($root)->assertInertia(fn (Assert $page) => $page->component('ProcurementComparison')->where('comparison.result.saving', null)->where('comparison.result.complete', false));
    $this->postJson($root.'/confirm', ['version' => 1, 'confirmed' => true])->assertUnprocessable();
    $this->patchJson($root, procurementQuotes($needs))->assertOk()->assertJsonPath('comparison.version', 2)
        ->assertJsonPath('comparison.result.quantity', '100.000')->assertJsonPath('comparison.result.separate_total', '11000.00')
        ->assertJsonPath('comparison.result.combined_total', '9600.00')->assertJsonPath('comparison.result.saving', '1400.00')
        ->assertJsonPath('comparison.result.allocations.0.combined_cost', '3840.00')->assertJsonPath('comparison.result.allocations.1.combined_cost', '5760.00')
        ->assertJsonPath('comparison.result.allocations.0.saving', '660.00')->assertJsonPath('comparison.result.allocations.1.saving', '740.00');
    $this->patchJson($root, procurementQuotes($needs))->assertConflict();
    $duplicate = $this->postJson('/procurement/comparisons', procurementSelection($needs))->assertOk()->json('id');
    $this->patchJson('/procurement/comparisons/'.$duplicate, procurementQuotes($needs))->assertOk();
    $this->postJson($root.'/confirm', ['version' => 2, 'confirmed' => false])->assertUnprocessable();
    $this->postJson($root.'/confirm', ['version' => 2, 'confirmed' => true])->assertOk()->assertJsonPath('comparison.status', 'confirmed')->assertJsonPath('comparison.version', 3);
    $this->postJson($root.'/confirm', ['version' => 2, 'confirmed' => true])->assertOk()->assertJsonPath('comparison.version', 3);
    $this->postJson('/procurement/comparisons/'.$duplicate.'/confirm', ['version' => 2, 'confirmed' => true])->assertConflict();
    $this->postJson('/procurement/comparisons', procurementSelection($needs))->assertConflict();
    $this->patchJson('/procurement/needs/'.$needs[0]->id, [...procurementNeedPayload($needs[0]->participation), 'version' => 1])->assertConflict();
    $this->deleteJson('/procurement/needs/'.$needs[0]->id, ['version' => 1])->assertConflict();
    $this->patchJson($root, procurementQuotes($needs, 3))->assertConflict();
    expect(ProcurementNeed::query()->where('confirmed_comparison_id', $id)->count())->toBe(2)
        ->and($needs[0]->participation->fresh()->planned_cost)->toBeNull();
    $this->postJson($root.'/cancel', ['version' => 2, 'confirmed' => true])->assertConflict();
    $this->postJson($root.'/cancel', ['version' => 3, 'confirmed' => true])->assertOk()->assertJsonPath('comparison.status', 'canceled');
    $this->postJson($root.'/cancel', ['version' => 3, 'confirmed' => true])->assertOk()->assertJsonPath('comparison.version', 4);
    expect(ProcurementNeed::query()->whereNotNull('confirmed_comparison_id')->count())->toBe(0);
    $this->postJson('/procurement/comparisons/'.$duplicate.'/confirm', ['version' => 2, 'confirmed' => true])->assertOk();
});

it('rejects unsupported units duplicate contracts foreign needs and tampered selections and quotes', function () {
    $user = procurementUser();
    $needs = procurementPair($user);
    $other = procurementUser();
    $foreign = procurementPair($other);
    $this->actingAs($user);
    $this->postJson('/procurement/comparisons', procurementSelection([$needs[0], $foreign[0]]))->assertNotFound();
    $this->postJson('/procurement/comparisons', procurementSelection([$needs[0], $needs[0]]))->assertUnprocessable();
    $another = ProcurementNeed::query()->create([...procurementNeedPayload($needs[0]->participation), 'version' => 1, 'match_key' => 'крепеж м8']);
    $this->postJson('/procurement/comparisons', procurementSelection([$needs[0], $another]))->assertUnprocessable();
    $needs[1]->update(['unit' => 'кг']);
    $this->postJson('/procurement/comparisons', procurementSelection($needs))->assertUnprocessable();
    $needs[1]->update(['unit' => 'шт']);
    $id = $this->postJson('/procurement/comparisons', procurementSelection($needs))->assertOk()->json('id');
    $root = '/procurement/comparisons/'.$id;
    $payload = procurementQuotes($needs);
    $bad = $payload;
    $bad['offers'][0]['need_id'] = $foreign[0]->id;
    $this->patchJson($root, $bad)->assertUnprocessable();
    foreach (['-1', '1e5', '1.001', '1000000'] as $price) {
        $bad = $payload;
        $bad['combined_offer']['unit_price'] = $price;
        $this->patchJson($root, $bad)->assertUnprocessable();
    }
    $payload['selection'] = [];
    $payload['confirmed_result'] = ['saving' => '999999999'];
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('comparison.result.saving', '1400.00')->assertJsonCount(2, 'comparison.selection');
    $this->actingAs($other)->get($root)->assertNotFound();
    $this->patchJson($root, procurementQuotes($foreign))->assertNotFound();
});

it('keeps incomplete dated or incompatible quotes explicit and refuses late expired or negative saving confirmations', function () {
    $user = procurementUser();
    $needs = procurementPair($user);
    $id = $this->actingAs($user)->postJson('/procurement/comparisons', procurementSelection($needs))->assertOk()->json('id');
    $root = '/procurement/comparisons/'.$id;
    $payload = procurementQuotes($needs);
    $version = 1;
    foreach ([['delivery_cost', null], ['reference', null], ['delivery_on', '2026-11-11'], ['valid_until', '2026-10-27'], ['quoted_on', '2026-10-29']] as [$field, $value]) {
        $bad = $payload;
        $bad['version'] = $version++;
        $bad['combined_offer'][$field] = $value;
        $this->patchJson($root, $bad)->assertOk()->assertJsonPath('comparison.result.complete', false)->assertJsonPath('comparison.result.saving', null);
        $this->postJson($root.'/confirm', ['version' => $version, 'confirmed' => true])->assertUnprocessable();
    }
    $payload['version'] = $version++;
    $payload['combined_offer']['unit_price'] = '104.00';
    $payload['combined_offer']['delivery_cost'] = '600.01';
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('comparison.result.saving', '-0.01');
    $this->postJson($root.'/confirm', ['version' => $version, 'confirmed' => true])->assertUnprocessable();
    $payload['version'] = $version++;
    $payload['combined_offer']['delivery_cost'] = '600';
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('comparison.result.saving', '0.00');
    $this->postJson($root.'/confirm', ['version' => $version, 'confirmed' => true])->assertUnprocessable();
    $needs[0]->update(['specification' => null, 'required_on' => null]);
    $id2 = $this->postJson('/procurement/comparisons', procurementSelection($needs))->assertOk()->json('id');
    $this->patchJson('/procurement/comparisons/'.$id2, procurementQuotes($needs))->assertOk()->assertJsonPath('comparison.result.complete', false);
});

it('uses exact fractional quantities line rounding and deterministic largest remainders without overflow', function () {
    $user = procurementUser();
    $needs = procurementPair($user);
    $needs[0]->update(['quantity' => '0.125']);
    $needs[1]->update(['quantity' => '0.875']);
    $id = $this->actingAs($user)->postJson('/procurement/comparisons', procurementSelection($needs))->assertOk()->json('id');
    $payload = procurementQuotes($needs);
    foreach ($payload['offers'] as &$offer) {
        $offer['unit_price'] = '0,04';
        $offer['delivery_cost'] = '0';
    }
    unset($offer);
    $payload['combined_offer']['unit_price'] = '0,01';
    $payload['combined_offer']['delivery_cost'] = '0';
    $this->patchJson('/procurement/comparisons/'.$id, $payload)->assertOk()->assertJsonPath('comparison.result.quantity', '1.000')
        ->assertJsonPath('comparison.result.separate_total', '0.05')->assertJsonPath('comparison.result.combined_total', '0.01')
        ->assertJsonPath('comparison.result.allocations.0.combined_cost', '0.00')->assertJsonPath('comparison.result.allocations.1.combined_cost', '0.01');
    $service = app(ProcurementService::class);
    $weights = [];
    for ($i = 1; $i <= 20; $i++) {
        $weights[] = ['id' => $i, 'weight' => 99999999];
    }
    $allocated = $service->distribute(200000000000001, $weights);
    expect(array_sum($allocated))->toBe(200000000000001)->and($allocated[1])->toBe(10000000000001)
        ->and($allocated[20])->toBe(10000000000000);
});

it('isolates team and personal scopes and enforces viewers archive membership expiry and changing sources', function () {
    $owner = procurementUser();
    $member = procurementUser();
    $viewer = procurementUser();
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Общая закупка']);
    foreach ([$owner->id => 'owner', $member->id => 'member', $viewer->id => 'viewer'] as $id => $role) {
        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $id, 'role' => $role]);
    }
    $personal = procurementPair($owner);
    $teamNeeds = procurementPair($owner, $team);
    $scope = '?team_id='.$team->id;
    $this->actingAs($member)->get('/procurement'.$scope)->assertInertia(fn (Assert $page) => $page->has('needs', 2));
    $this->postJson('/procurement/comparisons'.$scope, procurementSelection([$personal[0], $teamNeeds[0]]))->assertNotFound();
    $id = $this->postJson('/procurement/comparisons'.$scope, procurementSelection($teamNeeds))->assertOk()->json('id');
    $root = '/procurement/comparisons/'.$id;
    $this->patchJson($root.$scope, procurementQuotes($teamNeeds))->assertOk();
    $this->get($root)->assertNotFound();
    $this->actingAs($viewer)->get($root.$scope)->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
    $this->postJson($root.'/confirm'.$scope, ['version' => 2, 'confirmed' => true])->assertForbidden();
    $team->update(['archived_at' => now()]);
    $this->actingAs($member)->postJson($root.'/confirm'.$scope, ['version' => 2, 'confirmed' => true])->assertConflict();
    $team->update(['archived_at' => null]);
    $p = $teamNeeds[0]->participation;
    $p->delete();
    $this->get($root.$scope)->assertInertia(fn (Assert $page) => $page->has('comparison.stale_reasons', 1));
    $this->postJson($root.'/confirm'.$scope, ['version' => 2, 'confirmed' => true])->assertConflict();
    $p->restore();
    $this->postJson($root.'/confirm'.$scope, ['version' => 2, 'confirmed' => true])->assertOk();
    $p->update(['stage' => 'lost']);
    $this->get('/procurement'.$scope)->assertInertia(fn (Assert $page) => $page->has('needs', 1));
    $this->postJson($root.'/cancel'.$scope, ['version' => 3, 'confirmed' => true])->assertOk();
    expect(ProcurementNeed::query()->whereNotNull('confirmed_comparison_id')->count())->toBe(0);
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->delete();
    $this->get($root.$scope)->assertNotFound();
    Entitlement::query()->where('user_id', $viewer->id)->update(['ends_at' => now()]);
    $this->actingAs($viewer)->get($root.$scope)->assertForbidden();
    $this->get('/procurement'.$scope)->assertInertia(fn (Assert $page) => $page->where('enabled', false)->has('needs', 0));
    expect(DB::table('team_activity_logs')->where('action', 'procurement_confirm')->count())->toBe(1);
});

it('invalidates draft source versions and retains confirmed snapshots when quote validity elapses', function () {
    $user = procurementUser();
    $needs = procurementPair($user);
    $id = $this->actingAs($user)->postJson('/procurement/comparisons', procurementSelection($needs))->assertOk()->json('id');
    $root = '/procurement/comparisons/'.$id;
    $needs[0]->update(['quantity' => '50', 'version' => 2]);
    $this->patchJson($root, procurementQuotes($needs))->assertConflict();
    $this->postJson($root.'/confirm', ['version' => 1, 'confirmed' => true])->assertConflict();
    $this->get($root)->assertInertia(fn (Assert $page) => $page->where('comparison.selection.0.quantity', '40.000')->has('comparison.stale_reasons', 1));
    $needs[0]->update(['quantity' => '40', 'version' => 1]);
    $this->patchJson($root, procurementQuotes($needs))->assertOk();
    $this->postJson($root.'/confirm', ['version' => 2, 'confirmed' => true])->assertOk();
    $this->travel(12)->days();
    Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()->addDay()]);
    $this->get($root)->assertInertia(fn (Assert $page) => $page->where('comparison.result.saving', '1400.00')->where('comparison.status', 'confirmed')
        ->where('comparison.current_reasons', fn ($reasons) => collect($reasons)->contains(fn ($reason) => str_contains($reason, 'срок действия цены истёк'))));
    expect(ProcurementComparison::query()->findOrFail($id)->confirmed_result['saving'])->toBe('1400.00');
});

it('calculates the largest allowed combined quantity price and delivery without integer overflow', function () {
    $selection = [];
    $offers = [];
    $quote = procurementQuotes([])['combined_offer'];
    $quote['unit_price'] = '999999.99';
    $quote['delivery_cost'] = '999999999.99';
    for ($i = 1; $i <= 20; $i++) {
        $selection[] = ['id' => $i, 'participation_id' => $i, 'contract' => 'Контракт '.$i, 'title' => 'Деталь', 'specification' => 'Модель A',
            'unit' => 'шт', 'quantity' => '99999.999', 'required_on' => '2026-11-10'];
        $offers[] = ['need_id' => $i, ...$quote];
    }
    $comparison = new ProcurementComparison(['selection' => $selection, 'offers' => $offers, 'combined_offer' => $quote, 'conditions_note' => 'Цены и состав сопоставимы.']);
    $result = app(ProcurementService::class)->calculate($comparison);
    expect($result['quantity'])->toBe('1999999.980')->and($result['combined_total'])->toBe('2000999959999.99')
        ->and($result['separate_total'])->toBe('2019999959999.80')->and($result['saving'])->toBe('18999999999.81');
});
