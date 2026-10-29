<?php

use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\PriceCatalog;
use App\Models\PriceCatalogItem;
use App\Models\User;
use App\Models\WinnerOpportunity;
use App\Services\PlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.local_mvp_full_access.enabled', false);
});

function winnerUser(string $plan = 'ultra'): User
{
    $user = User::factory()->create();
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->byCode($plan)->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 30, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

    return $user;
}

function winnerEvidence(): array
{
    return ['version' => 0, 'confirmed' => true, 'creation_key' => (string) Str::uuid(), 'title' => 'Оснащение офиса №123',
        'winner_name' => 'ООО Исполнитель', 'source_url' => 'https://example.test/protocol/123',
        'source_reference' => 'Итоговый протокол №123, стр. 2, пункт 4', 'source_quote' => 'Победителем по поставке мебели признано ООО Исполнитель.',
        'result_on' => '2026-10-25', 'checked_on' => '2026-10-29', 'scope' => 'Мебель: стол офисный'];
}

function winnerCatalog(User $user): PriceCatalogItem
{
    $catalog = PriceCatalog::query()->create(['user_id' => $user->id, 'version' => 1]);

    return PriceCatalogItem::query()->create(['price_catalog_id' => $catalog->id, 'name' => 'Стол офисный', 'characteristics' => 'Дуб, 120x80',
        'price' => '12000.99', 'quantity' => null, 'unit' => 'шт', 'region' => null]);
}

function winnerCompose(int $item, int $version = 1): array
{
    return ['version' => $version, 'catalog_version' => 1, 'item_ids' => [$item], 'confirmed' => true,
        'rationale' => 'В предмете закупки есть офисные столы. Материал и размеры нужно уточнить.',
        'company' => 'Мебельная мастерская', 'contacts' => 'sales@example.test'];
}

it('gates private pages every operation and exports for lower expired and preview tiers', function (string $plan) {
    $owner = winnerUser();
    $id = $this->actingAs($owner)->postJson('/winner-opportunities', winnerEvidence())->assertOk()->json('id');
    $user = $plan === 'preview' ? User::factory()->create() : winnerUser($plan === 'expired' ? 'ultra' : $plan);
    if ($plan === 'expired') {
        Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()]);
    }
    $this->actingAs($user)->get('/winner-opportunities')->assertInertia(fn (Assert $p) => $p->component('WinnerOpportunities')->where('enabled', false)->where('opportunities', null));
    $this->get('/winner-opportunities/'.$id)->assertForbidden();
    $this->getJson('/winner-opportunities/'.$id.'/export?version=1')->assertForbidden();
    $this->postJson('/winner-opportunities', winnerEvidence())->assertForbidden();
    $this->patchJson('/winner-opportunities/'.$id, winnerEvidence())->assertForbidden();
    $this->postJson('/winner-opportunities/'.$id.'/compose', [])->assertForbidden();
    $this->patchJson('/winner-opportunities/'.$id.'/draft', [])->assertForbidden();
    $this->postJson('/winner-opportunities/'.$id.'/outcome', [])->assertForbidden();
    $this->patchJson('/winner-opportunities/'.$id.'/archive', [])->assertForbidden();
})->with(['basic', 'pro', 'expired', 'preview']);

it('follows owner developer tier and closes access at the exact expiry boundary', function () {
    $owner = winnerUser();
    $owner->forceFill(['role' => UserRole::SuperAdmin, 'developer_plan_code' => 'basic'])->save();
    $this->actingAs($owner)->postJson('/winner-opportunities', winnerEvidence())->assertForbidden();
    $owner->forceFill(['developer_plan_code' => 'ultra'])->save();
    $this->actingAs($owner->fresh())->postJson('/winner-opportunities', winnerEvidence())->assertOk();
    $user = winnerUser();
    $this->actingAs($user)->postJson('/winner-opportunities', winnerEvidence())->assertOk();
    $this->travel(1)->days();
    $this->postJson('/winner-opportunities', winnerEvidence())->assertForbidden();
});

it('requires a complete manually confirmed citation with ordered nonfuture dates and https', function () {
    $this->actingAs(winnerUser());
    foreach (['title', 'winner_name', 'source_url', 'source_reference', 'source_quote', 'result_on', 'checked_on', 'scope'] as $key) {
        $data = winnerEvidence();
        $data[$key] = '';
        $this->postJson('/winner-opportunities', $data)->assertUnprocessable();
    }
    foreach ([['source_url' => 'javascript:alert(1)'], ['source_url' => 'http://example.test'], ['confirmed' => false],
        ['result_on' => '2026-10-30'], ['checked_on' => '2026-10-24'], ['checked_on' => '2026-10-30']] as $bad) {
        $this->postJson('/winner-opportunities', [...winnerEvidence(), ...$bad])->assertUnprocessable();
    }
    expect(WinnerOpportunity::query()->count())->toBe(0);
});

it('isolates owners and rejects implicit team scope and client controlled ownership or draft fields', function () {
    $a = winnerUser();
    $b = winnerUser();
    $id = $this->actingAs($a)->postJson('/winner-opportunities', [...winnerEvidence(), 'user_id' => $b->id, 'body' => 'forged', 'outcome' => 'requested'])->json('id');
    expect(WinnerOpportunity::query()->findOrFail($id)->user_id)->toBe($a->id)
        ->and(WinnerOpportunity::query()->findOrFail($id)->body)->toBeNull()
        ->and(WinnerOpportunity::query()->findOrFail($id)->outcome)->toBe('new');
    $this->actingAs($b)->get('/winner-opportunities')->assertInertia(fn (Assert $p) => $p->has('opportunities.data', 0));
    $this->get('/winner-opportunities/'.$id)->assertNotFound();
    $this->patchJson('/winner-opportunities/'.$id, [...winnerEvidence(), 'version' => 1])->assertNotFound();
    $item = winnerCatalog($b);
    $this->postJson('/winner-opportunities/'.$id.'/compose', winnerCompose($item->id))->assertNotFound();
    $this->get('/winner-opportunities/'.$id.'/export?version=1')->assertNotFound();
    $this->get('/winner-opportunities?team_id=123')->assertUnprocessable();
    $this->actingAs($a)->postJson('/winner-opportunities/'.$id.'/compose?team_id=123', winnerCompose($item->id))->assertUnprocessable();
});

it('creates idempotently and prevents stale source changes and duplicate conflicting submissions', function () {
    $this->actingAs(winnerUser());
    $data = winnerEvidence();
    $id = $this->postJson('/winner-opportunities', $data)->assertOk()->json('id');
    $this->postJson('/winner-opportunities', $data)->assertOk()->assertJsonPath('id', $id);
    $this->postJson('/winner-opportunities', [...$data, 'title' => 'Другой тендер'])->assertConflict();
    $this->patchJson('/winner-opportunities/'.$id, [...$data, 'version' => 0])->assertConflict();
    $this->patchJson('/winner-opportunities/'.$id, [...$data, 'version' => 1, 'scope' => 'Мебель'])->assertOk();
    $this->patchJson('/winner-opportunities/'.$id, [...$data, 'version' => 1])->assertConflict();
    expect(WinnerOpportunity::query()->count())->toBe(1)->and(DB::table('winner_opportunity_events')->count())->toBe(2);
});

it('offers preliminary lexical reasons only from the personal catalog and never invents a price or contact', function () {
    $user = winnerUser();
    $item = winnerCatalog($user);
    $foreign = winnerCatalog(winnerUser());
    $id = $this->actingAs($user)->postJson('/winner-opportunities', winnerEvidence())->json('id');
    $this->get('/winner-opportunities/'.$id)->assertInertia(fn (Assert $p) => $p->has('candidates', 1)->where('candidates.0.item.id', $item->id)->has('candidates.0.terms', 2)->has('catalog.items', 1));
    $this->postJson('/winner-opportunities/'.$id.'/compose', winnerCompose($foreign->id))->assertUnprocessable();
    $this->postJson('/winner-opportunities/'.$id.'/compose', [...winnerCompose($item->id), 'catalog_version' => 0])->assertConflict();
    $this->postJson('/winner-opportunities/'.$id.'/compose', [...winnerCompose($item->id), 'confirmed' => false])->assertUnprocessable();
    $response = $this->postJson('/winner-opportunities/'.$id.'/compose', winnerCompose($item->id))->assertOk()->assertJsonPath('opportunity.stale', false);
    expect($response->json('opportunity.body'))->toContain('Стол офисный', 'ООО Исполнитель', 'Материал и размеры нужно уточнить', 'https://example.test/protocol/123', 'sales@example.test')
        ->not->toContain('12000.99');
    $this->get('/winner-opportunities/'.$id.'/export?version=2')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->patchJson('/winner-opportunities/'.$id.'/draft', ['version' => 2, 'confirmed' => true, 'body' => 'Мой проверенный текст'])->assertOk()->assertJsonPath('opportunity.version', 3);
    $this->patchJson('/winner-opportunities/'.$id.'/draft', ['version' => 2, 'confirmed' => true, 'body' => 'Старая вкладка'])->assertConflict();
    $this->get('/winner-opportunities/'.$id.'/export?version=2')->assertConflict();
    $this->get('/winner-opportunities/'.$id.'/export?version=3')->assertSee('Мой проверенный текст');
});

it('invalidates drafts after catalog changes or evidence corrections and preserves contacted snapshots', function () {
    $user = winnerUser();
    $item = winnerCatalog($user);
    $id = $this->actingAs($user)->postJson('/winner-opportunities', winnerEvidence())->json('id');
    $root = '/winner-opportunities/'.$id;
    $this->postJson($root.'/compose', winnerCompose($item->id))->assertOk();
    $this->postJson($root.'/outcome', ['version' => 2, 'confirmed' => true, 'outcome' => 'contacted', 'note' => 'Вручную отправлено по почте 29 октября'])->assertOk();
    $sent = DB::table('winner_opportunity_events')->where('action', 'contacted')->first();
    $snapshot = json_decode($sent->snapshot, true);
    expect($snapshot['body'])->toContain('Стол офисный')->and($snapshot['evidence']['winner_name'])->toBe('ООО Исполнитель');
    PriceCatalog::query()->where('user_id', $user->id)->update(['version' => 2]);
    $this->get($root)->assertInertia(fn (Assert $p) => $p->where('opportunity.stale', true));
    $this->get($root.'/export?version=3')->assertConflict();
    $this->patchJson($root.'/draft', ['version' => 3, 'confirmed' => true, 'body' => 'Правки'])->assertConflict();
    $this->postJson($root.'/outcome', ['version' => 3, 'confirmed' => true, 'outcome' => 'contacted', 'note' => 'Старый текст'])->assertConflict();
    $this->patchJson($root, [...winnerEvidence(), 'version' => 3, 'winner_name' => 'ООО Новый победитель'])->assertOk();
    expect(DB::table('winner_opportunity_events')->where('id', $sent->id)->value('snapshot'))->toBe($sent->snapshot);
    $this->postJson($root.'/outcome', ['version' => 4, 'confirmed' => true, 'outcome' => 'requested', 'note' => 'Прежний ответ относится к другому основанию'])->assertUnprocessable();
    $this->postJson($root.'/compose', [...winnerCompose($item->id, 4), 'catalog_version' => 2])->assertOk()->assertJsonPath('opportunity.stale', false);
    $this->postJson($root.'/outcome', ['version' => 5, 'confirmed' => true, 'outcome' => 'contacted', 'note' => 'Новый текст отправлен новому исполнителю'])->assertOk();
    $this->postJson($root.'/outcome', ['version' => 6, 'confirmed' => true, 'outcome' => 'requested', 'note' => 'Исполнитель запросил спецификацию, письмо №5'])->assertOk();
    $this->patchJson($root, [...winnerEvidence(), 'version' => 7, 'source_quote' => 'Уточнённый протокол'])->assertOk();
    $this->get($root.'/export?version=8')->assertConflict();
});

it('requires actual manual contact before a reply and keeps archives read only with recoverable history', function () {
    $user = winnerUser();
    $item = winnerCatalog($user);
    $id = $this->actingAs($user)->postJson('/winner-opportunities', winnerEvidence())->json('id');
    $root = '/winner-opportunities/'.$id;
    $this->postJson($root.'/outcome', ['version' => 1, 'confirmed' => true, 'outcome' => 'requested', 'note' => 'Заказ'])->assertUnprocessable();
    $this->postJson($root.'/outcome', ['version' => 1, 'confirmed' => true, 'outcome' => 'contacted', 'note' => 'Отправил'])->assertConflict();
    $this->postJson($root.'/compose', winnerCompose($item->id))->assertOk();
    $this->patchJson($root.'/archive', ['version' => 2, 'confirmed' => true, 'archived' => true])->assertOk();
    $this->get('/winner-opportunities')->assertInertia(fn (Assert $p) => $p->has('opportunities.data', 0));
    $this->get('/winner-opportunities?archived=1')->assertInertia(fn (Assert $p) => $p->has('opportunities.data', 1));
    $this->get($root)->assertOk();
    $this->postJson($root.'/compose', winnerCompose($item->id, 3))->assertConflict();
    $this->patchJson($root, [...winnerEvidence(), 'version' => 3])->assertConflict();
    $this->patchJson($root.'/archive', ['version' => 3, 'confirmed' => true, 'archived' => false])->assertOk();
    $this->postJson($root.'/outcome', ['version' => 4, 'confirmed' => true, 'outcome' => 'unsuitable', 'note' => 'Слишком поздний срок поставки'])->assertOk();
    $this->get($root)->assertInertia(fn (Assert $p) => $p->has('events.data', 4)->where('opportunity.outcome', 'unsuitable'));
});

it('paginates personal records and enforces active capacity on creation and restoration', function () {
    $user = winnerUser();
    $data = winnerEvidence();
    $evidence = array_diff_key($data, array_flip(['version', 'confirmed', 'creation_key']));
    for ($i = 0; $i < 100; $i++) {
        WinnerOpportunity::query()->create(['user_id' => $user->id, 'creation_key' => (string) Str::uuid(),
            'creation_hash' => hash('sha256', (string) $i), 'evidence' => [...$evidence, 'title' => 'Запись '.$i]]);
    }
    $this->actingAs($user)->get('/winner-opportunities')->assertInertia(fn (Assert $p) => $p->has('opportunities.data', 10)->where('opportunities.total', 100));
    $this->get('/winner-opportunities?page=2')->assertInertia(fn (Assert $p) => $p->has('opportunities.data', 10)->where('opportunities.current_page', 2));
    $this->postJson('/winner-opportunities', $data)->assertUnprocessable();
    $first = WinnerOpportunity::query()->first();
    $this->patchJson('/winner-opportunities/'.$first->id.'/archive', ['version' => 1, 'confirmed' => true, 'archived' => true])->assertOk();
    $this->postJson('/winner-opportunities', $data)->assertOk();
    $this->patchJson('/winner-opportunities/'.$first->id.'/archive', ['version' => 2, 'confirmed' => true, 'archived' => false])->assertUnprocessable();
    expect($first->fresh()->archived_at)->not->toBeNull();
});

it('rejects an oversized assembled draft atomically without replacing saved text', function () {
    $user = winnerUser();
    $item = winnerCatalog($user);
    $item->update(['name' => str_repeat('А', 300), 'characteristics' => str_repeat('Б', 1500)]);
    $ids = [$item->id];
    for ($i = 0; $i < 9; $i++) {
        $copy = $item->replicate();
        $copy->save();
        $ids[] = $copy->id;
    }
    $id = $this->actingAs($user)->postJson('/winner-opportunities', winnerEvidence())->json('id');
    $this->postJson('/winner-opportunities/'.$id.'/compose', [...winnerCompose($item->id), 'item_ids' => $ids,
        'rationale' => str_repeat('В', 2000), 'company' => str_repeat('Г', 500), 'contacts' => str_repeat('Д', 1000)])->assertUnprocessable();
    $row = WinnerOpportunity::query()->findOrFail($id);
    expect($row->body)->toBeNull()->and($row->version)->toBe(1);
});
