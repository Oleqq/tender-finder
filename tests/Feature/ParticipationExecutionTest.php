<?php

use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\ParticipationExecution;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.local_mvp_full_access.enabled', false);
});

function executionFixture(string $plan = 'ultra'): array
{
    $user = User::factory()->create();
    $dbPlan = app(PlanCatalog::class)->byCode($plan);
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => $dbPlan->id, 'code' => 'active_queries', 'status' => 'active', 'value' => 30, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Свой поиск', 'keywords' => ['сайт'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => (string) Str::uuid(), 'title' => 'Исполнение контракта', 'canonical_url' => 'https://example.test/execution', 'canonical_url_hash' => hash('sha256', (string) Str::uuid())]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);
    $p = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'won',
        'planned_revenue' => '300000.00', 'planned_cost' => '180000.00', 'security_cost' => '10000.00', 'commission_cost' => '10000.00', 'other_cost' => '10000.00',
        'actual_revenue' => '999999.00', 'actual_cost' => '888888.00']);

    return [$user, $tender, $p];
}

function executionPayload(array $execution): array
{
    return ['version' => $execution['version'], 'received_revenue' => '100000', 'remaining_revenue' => '200000', 'costs_confirmed' => true,
        'expenses' => array_map(fn (array $row): array => array_intersect_key([...$row, 'actual' => '0'], array_flip(['id', 'title', 'category', 'actual', 'remaining', 'note', 'task_id'])), $execution['expenses'])];
}

it('gates every execution response and mutation to active Ultra even from an old tab', function (string $plan) {
    [$user, $tender] = executionFixture($plan);
    $root = '/tenders/'.$tender->id.'/execution';
    $this->actingAs($user)->get($root)->assertForbidden()->assertDontSee('180000');
    $this->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertForbidden();
    $this->patchJson($root, [])->assertForbidden();
    expect(ParticipationExecution::query()->count())->toBe(0);
})->with(['basic', 'pro']);

it('captures a single immutable baseline without copying aggregate actuals or overwriting it on repeated start', function () {
    [$user, $tender, $p] = executionFixture();
    $root = '/tenders/'.$tender->id.'/execution';
    $this->actingAs($user)->postJson($root, ['confirmed' => false, 'economics_version' => 1])->assertUnprocessable();
    $this->postJson($root, ['confirmed' => true, 'economics_version' => 2])->assertConflict();
    $response = $this->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertOk()
        ->assertJsonPath('execution.version', 1)->assertJsonPath('execution.summary.planned_result', '90000.00')
        ->assertJsonPath('execution.summary.forecast_result', null)->assertJsonPath('execution.summary.complete', false)
        ->assertJsonPath('execution.received_revenue', null)->assertJsonPath('execution.expenses.0.actual', null);
    $baseline = $response->json('execution.baseline');
    $p->update(['planned_revenue' => '400000', 'economics_version' => 2]);
    $this->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertOk()->assertJsonPath('execution.baseline', $baseline)->assertJsonPath('execution.baseline_changed', true);
    expect(ParticipationExecution::query()->count())->toBe(1);
    $this->get($root)->assertInertia(fn (Assert $page) => $page->component('ParticipationExecution')->where('execution.baseline.planned_revenue', '300000.00'));
});

it('explains a 90000 to 54000 forecast through contractor costs and the linked task without counting aggregate actuals', function () {
    [$user, $tender, $p] = executionFixture();
    $task = $p->items()->create(['title' => 'Дополнительные часы подрядчика']);
    $root = '/tenders/'.$tender->id.'/execution';
    $execution = $this->actingAs($user)->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertOk()->json('execution');
    $payload = executionPayload($execution);
    $payload['expenses'][0]['actual'] = '40000';
    $payload['expenses'][0]['remaining'] = '140000';
    $payload['expenses'][] = ['id' => (string) Str::uuid(), 'title' => 'Часы подрядчика', 'category' => 'planned_cost', 'actual' => '10000', 'remaining' => '26000', 'task_id' => $task->id, 'note' => '20 часов по 1 800 ₽'];
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('execution.version', 2)
        ->assertJsonPath('execution.summary.planned_result', '90000.00')->assertJsonPath('execution.summary.forecast_result', '54000.00')
        ->assertJsonPath('execution.summary.actual_costs', '50000.00')->assertJsonPath('execution.summary.remaining_costs', '196000.00')
        ->assertJsonPath('execution.summary.forecast_costs', '246000.00')->assertJsonPath('execution.summary.result_change', '-36000.00')
        ->assertJsonPath('execution.summary.cash_balance', '50000.00')->assertJsonPath('execution.categories.0.deviation', '36000.00')
        ->assertJsonPath('execution.expenses.4.task.title', 'Дополнительные часы подрядчика')->assertJsonPath('execution.expenses.4.note', '20 часов по 1 800 ₽');
    expect($p->fresh()->actual_cost)->toBe('888888.00')->and($p->fresh()->economics_version)->toBe(1);
    $this->patchJson($root, $payload)->assertConflict();
    Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()]);
    $this->patchJson($root, [...$payload, 'version' => 2])->assertForbidden();
    $this->get($root)->assertForbidden()->assertDontSee('Часы подрядчика');
});

it('keeps unknown values incomplete and requires explicit completeness and zero values', function () {
    [$user, $tender, $p] = executionFixture();
    $p->update(['other_cost' => null]);
    $root = '/tenders/'.$tender->id.'/execution';
    $execution = $this->actingAs($user)->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertOk()->assertJsonPath('execution.summary.planned_result', null)->json('execution');
    $payload = executionPayload($execution);
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('execution.summary.forecast_result', null)->assertJsonPath('execution.summary.remaining_costs', null);
    $payload['version'] = 2;
    $payload['expenses'][3]['remaining'] = '0';
    $payload['costs_confirmed'] = false;
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('execution.summary.forecast_result', null)->assertJsonPath('execution.summary.forecast_costs', '200000.00');
    $payload['version'] = 3;
    $payload['costs_confirmed'] = true;
    $payload['remaining_revenue'] = null;
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('execution.summary.forecast_result', null);
    $payload['version'] = 4;
    $payload['remaining_revenue'] = '0';
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('execution.summary.forecast_result', '-100000.00')->assertJsonPath('execution.summary.result_change', null);
});

it('calculates exact kopecks with comma input and validates amounts row uniqueness categories and task ownership', function () {
    [$user, $tender, $p] = executionFixture();
    [, , $foreign] = executionFixture();
    $task = $foreign->items()->create(['title' => 'Чужая задача']);
    $root = '/tenders/'.$tender->id.'/execution';
    $execution = $this->actingAs($user)->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertOk()->json('execution');
    $payload = executionPayload($execution);
    foreach (['-1', '1.001', '1e4', '10000000000.00', 'abc'] as $amount) {
        $this->patchJson($root, [...$payload, 'received_revenue' => $amount])->assertUnprocessable();
    }
    $bad = $payload;
    $bad['expenses'][0]['task_id'] = $task->id;
    $this->patchJson($root, $bad)->assertUnprocessable();
    $bad = $payload;
    $bad['expenses'][0]['id'] = $bad['expenses'][1]['id'];
    $this->patchJson($root, $bad)->assertUnprocessable();
    $bad = $payload;
    $bad['expenses'][0]['category'] = 'other_cost';
    $this->patchJson($root, $bad)->assertUnprocessable();
    $payload['received_revenue'] = '0,10';
    $payload['remaining_revenue'] = '0,20';
    foreach ($payload['expenses'] as &$row) {
        $row['actual'] = '0.01';
        $row['remaining'] = '0.02';
    }
    unset($row);
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('execution.summary.forecast_costs', '0.12')
        ->assertJsonPath('execution.summary.forecast_result', '0.18')->assertJsonPath('execution.summary.cash_balance', '0.06');
    expect(ParticipationExecution::query()->sole()->version)->toBe(2);
});

it('isolates personal and team plans and observes member viewer archive removal and stage boundaries', function () {
    [$owner, $tender, $personal] = executionFixture();
    [$member] = executionFixture();
    [$viewer] = executionFixture();
    [$outsider] = executionFixture();
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Исполнение']);
    foreach ([$owner->id => 'owner', $member->id => 'member', $viewer->id => 'viewer'] as $id => $role) {
        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $id, 'role' => $role]);
    }
    $p = TenderParticipation::query()->create(['user_id' => $owner->id, 'team_id' => $team->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    $root = '/tenders/'.$tender->id.'/execution';
    $url = $root.'?team_id='.$team->id;
    $start = ['confirmed' => true, 'economics_version' => 1];
    $this->actingAs($member)->postJson($url, $start)->assertConflict();
    $p->update(['stage' => 'won']);
    $teamExecution = $this->postJson($url, $start)->assertOk()->json('execution');
    $this->get($root)->assertNotFound();
    $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
    $this->postJson($url, $start)->assertForbidden();
    $this->patchJson($url, executionPayload($teamExecution))->assertForbidden();
    $this->actingAs($outsider)->get($url)->assertNotFound();
    $this->postJson($url, $start)->assertNotFound();
    $this->actingAs($owner)->postJson($root, $start)->assertOk()->assertJsonPath('execution.baseline.planned_revenue', '300000.00');
    expect(ParticipationExecution::query()->count())->toBe(2);
    $team->update(['archived_at' => now()]);
    $this->actingAs($member)->patchJson($url, executionPayload($teamExecution))->assertConflict();
    $team->update(['archived_at' => null]);
    $p->delete();
    $this->get($url)->assertNotFound();
    $this->patchJson($url, executionPayload($teamExecution))->assertNotFound();
    $p->restore();
    $task = $p->items()->create(['title' => 'Проверка']);
    $payload = executionPayload($teamExecution);
    $payload['expenses'][0]['task_id'] = $task->id;
    $this->patchJson($url, $payload)->assertOk();
    $task->delete();
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('execution.expenses.0.task_missing', true)->where('execution.expenses.0.task', null));
    $this->patchJson($url, [...$payload, 'version' => 2])->assertOk();
    $p->update(['stage' => 'lost']);
    $this->patchJson($url, [...$payload, 'version' => 3])->assertConflict();
    expect(DB::table('team_activity_logs')->where('action', 'execution_updated')->count())->toBe(2);
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->delete();
    $this->get($url)->assertNotFound();
    expect($personal->fresh()->economics_version)->toBe(1);
});

it('bounds the baseline and 100 expense rows without losing kopecks or replacing the snapshot from client input', function () {
    [$user, $tender, $p] = executionFixture();
    $root = '/tenders/'.$tender->id.'/execution';
    $p->update(['planned_cost' => '10000000000']);
    $this->actingAs($user)->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertUnprocessable();
    expect(ParticipationExecution::query()->count())->toBe(0);
    $p->update(['planned_cost' => '180000']);
    $execution = $this->postJson($root, ['confirmed' => true, 'economics_version' => 1])->assertOk()->json('execution');
    $payload = executionPayload($execution);
    while (count($payload['expenses']) < 100) {
        $payload['expenses'][] = [...$payload['expenses'][0], 'id' => (string) Str::uuid()];
    }
    foreach ($payload['expenses'] as &$row) {
        $row['actual'] = '9999999999.99';
        $row['remaining'] = '0';
    }
    unset($row);
    $payload['received_revenue'] = '9999999999.99';
    $payload['remaining_revenue'] = '9999999999.99';
    $payload['baseline'] = ['planned_revenue' => '0'];
    $this->patchJson($root, $payload)->assertOk()->assertJsonPath('execution.summary.forecast_costs', '999999999999.00')
        ->assertJsonPath('execution.summary.forecast_result', '-979999999999.02')->assertJsonPath('execution.baseline.planned_revenue', '300000.00');
    $payload['version'] = 2;
    $payload['expenses'][] = [...$payload['expenses'][0], 'id' => (string) Str::uuid()];
    $this->patchJson($root, $payload)->assertUnprocessable();
});

it('respects the owners selected developer tier without changing the original entitlement', function () {
    [$user, $tender] = executionFixture();
    $user->forceFill(['role' => UserRole::SuperAdmin, 'developer_plan_code' => 'basic'])->save();
    $root = '/tenders/'.$tender->id.'/execution';
    $this->actingAs($user)->get($root)->assertForbidden();
    $user->forceFill(['developer_plan_code' => 'ultra'])->save();
    $this->actingAs($user->fresh())->get($root)->assertOk();
    expect(Entitlement::query()->where('user_id', $user->id)->sole()->plan_id)->toBe(app(PlanCatalog::class)->byCode('ultra')->id);
});
