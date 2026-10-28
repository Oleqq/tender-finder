<?php

use App\Models\Entitlement;
use App\Models\ProposalBlock;
use App\Models\ProposalDraft;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\ProposalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('tender.temporary_full_access_until', null);
    config()->set('tender.local_mvp_full_access.enabled', false);
});

function proposalFixture(string $plan = 'pro'): array
{
    $user = User::factory()->create();
    $dbPlan = app(PlanCatalog::class)->byCode($plan);
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => $dbPlan->id, 'code' => 'active_queries', 'status' => 'active', 'value' => 10, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Мои закупки', 'keywords' => ['сайт'], 'status' => 'active']);
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => 'proposal-'.bin2hex(random_bytes(4)), 'title' => 'Разработка сайта', 'canonical_url' => 'https://example.test/proposal', 'canonical_url_hash' => hash('sha256', bin2hex(random_bytes(8))), 'budget_amount' => '150000', 'currency' => 'RUB']);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);

    return [$user, $tender];
}

function proposalPayload(Tender $tender, array $blocks = []): array
{
    return ['version' => 0, 'tender_hash' => app(ProposalService::class)->fingerprint($tender), 'confirmed' => true,
        'blocks' => $blocks, 'fields' => ['company' => 'Наша компания', 'price' => '1234,56', 'delivery' => '10 рабочих дней', 'contacts' => 'contact@example.test']];
}

it('opens Pro and Ultra while keeping library content out of Basic preview and expired responses', function (string $plan, bool $enabled) {
    [$user, $tender] = proposalFixture($plan);
    ProposalBlock::query()->create(['user_id' => $user->id, 'title' => 'Секретный опыт', 'category' => 'experience', 'body' => 'Подтверждённый проект']);
    $this->actingAs($user)->get('/proposals')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ProposalLibrary')->where('enabled', $enabled)->has('blocks', $enabled ? 1 : 0));
    if (! $enabled) {
        $this->get('/tenders/'.$tender->id.'/proposal')->assertForbidden();
        $this->postJson('/proposals/blocks', ['title' => 'Test'])->assertForbidden();
    }
    Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()]);
    $this->get('/proposals')->assertInertia(fn (Assert $page) => $page->where('enabled', false)->has('blocks', 0));
    $this->postJson('/tenders/'.$tender->id.'/proposal/compose', proposalPayload($tender))->assertForbidden();
})->with([['basic', false], ['pro', true], ['ultra', true]]);

it('requires confirmed blocks versions and private ownership and preserves drafted text after deletion', function () {
    [$user, $tender] = proposalFixture();
    $this->actingAs($user);
    $body = ['title' => 'Наш опыт', 'category' => 'experience', 'body' => 'Проект 2026', 'version' => 0];
    $this->postJson('/proposals/blocks', $body)->assertUnprocessable();
    $block = $this->postJson('/proposals/blocks', [...$body, 'confirmed' => true])->assertOk()->json('blocks.0');
    $this->patchJson('/proposals/blocks/'.$block['id'], [...$body, 'confirmed' => true])->assertConflict();
    $root = '/tenders/'.$tender->id.'/proposal';
    $this->postJson($root.'/compose', proposalPayload($tender, [['id' => $block['id'], 'version' => 1]]))->assertOk()->assertJsonPath('draft.missing.0', 'заказчик');
    $saved = ProposalDraft::query()->sole()->body;
    $this->deleteJson('/proposals/blocks/'.$block['id'], ['version' => 1])->assertOk();
    $this->get($root)->assertInertia(fn (Assert $page) => $page->where('draft.body', $saved)->where('draft.stale_blocks.0', 'Наш опыт'));
    [$other] = proposalFixture();
    $this->actingAs($other)->get($root)->assertNotFound();
    $this->patchJson('/proposals/blocks/'.$block['id'], [...$body, 'version' => 1, 'confirmed' => true])->assertNotFound();
});

it('assembles confirmed values leaves missing and unknown fields visible and never substitutes tender budget for price', function () {
    [$user, $tender] = proposalFixture();
    $block = ProposalBlock::query()->create(['user_id' => $user->id, 'title' => 'Услуги', 'category' => 'services', 'body' => 'Создание сайта. {{неизвестное_поле}}']);
    $root = '/tenders/'.$tender->id.'/proposal';
    $payload = proposalPayload($tender, [['id' => $block->id, 'version' => 1]]);
    $payload['fields']['price'] = '';
    $response = $this->actingAs($user)->postJson($root.'/compose', $payload)->assertOk();
    $body = $response->json('draft.body');
    expect($body)->toContain('Разработка сайта', 'Создание сайта.', '{{заказчик}}', '{{цена_предложения}}', '{{неизвестное_поле}}')->not->toContain('150000');
    $this->get($root.'/export?version=1')->assertUnprocessable();
    $this->patchJson($root, ['version' => 1, 'tender_hash' => $payload['tender_hash'], 'confirmed' => true, 'body' => "Предложение\nПодтверждённая цена 1 234,56 ₽"])->assertOk()->assertJsonPath('draft.version', 2);
    $this->get($root.'/export?version=2')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('1 234,56 ₽');
    $this->get($root.'/export?version=1')->assertConflict();
});

it('uses exact kopecks rejects foreign blocks and templates and stale source or draft versions', function () {
    [$user, $tender] = proposalFixture();
    [$other] = proposalFixture();
    $foreign = ProposalBlock::query()->create(['user_id' => $other->id, 'title' => 'Чужое', 'category' => 'services', 'body' => 'Секрет']);
    $root = '/tenders/'.$tender->id.'/proposal';
    $this->actingAs($user)->postJson($root.'/compose', proposalPayload($tender, [['id' => $foreign->id, 'version' => 1]]))->assertNotFound();
    $payload = proposalPayload($tender);
    $this->postJson($root.'/compose', [...$payload, 'template_id' => $foreign->id, 'template_version' => 1])->assertNotFound();
    $this->postJson($root.'/compose', $payload)->assertOk()->assertJsonFragment(['version' => 1]);
    expect(ProposalDraft::query()->sole()->body)->toContain('1 234,56 ₽');
    $this->postJson($root.'/compose', $payload)->assertConflict();
    $tender->update(['title' => 'Новые условия']);
    $this->postJson($root.'/compose', [...$payload, 'version' => 1])->assertConflict();
    $this->get($root)->assertInertia(fn (Assert $page) => $page->where('draft.tender_changed', true));
    expect(ProposalDraft::query()->count())->toBe(1);
});

it('supports a personal template and rejects recursive user values without inventing missing information', function () {
    [$user, $tender] = proposalFixture();
    $this->actingAs($user)->postJson('/proposals/blocks', ['title' => 'Шаблон', 'category' => 'template', 'body' => 'Без места для блоков', 'version' => 0, 'confirmed' => true])->assertUnprocessable();
    $block = ProposalBlock::query()->create(['user_id' => $user->id, 'title' => 'Состав', 'category' => 'works', 'body' => 'Дизайн и разработка']);
    $template = ProposalBlock::query()->create(['user_id' => $user->id, 'title' => 'Мой шаблон', 'category' => 'template', 'body' => "КП {{название_закупки}}\n{{блоки}}\n{{компания}}"]);
    $payload = proposalPayload($tender, [['id' => $block->id, 'version' => 1]]);
    $payload['fields']['company'] = '{{контакты}}';
    $this->postJson('/tenders/'.$tender->id.'/proposal/compose', [...$payload, 'template_id' => $template->id, 'template_version' => 1])->assertOk()
        ->assertJsonPath('draft.missing.0', 'контакты');
    expect(ProposalDraft::query()->sole()->body)->toContain('КП Разработка сайта', 'Дизайн и разработка', '{{контакты}}')->not->toContain('contact@example.test');
});

it('isolates team and personal drafts and respects viewer archive removal and membership boundaries', function () {
    [$owner, $tender] = proposalFixture();
    [$member] = proposalFixture();
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Команда']);
    DB::table('team_members')->insert([['team_id' => $team->id, 'user_id' => $owner->id, 'role' => 'owner'], ['team_id' => $team->id, 'user_id' => $member->id, 'role' => 'viewer']]);
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => SearchQuery::query()->where('user_id', $owner->id)->sole()->id, 'shared_by_id' => $owner->id]);
    $root = '/tenders/'.$tender->id.'/proposal';
    $payload = proposalPayload($tender);
    $this->actingAs($owner)->postJson($root.'/compose?team_id='.$team->id, $payload)->assertOk();
    $this->get($root)->assertInertia(fn (Assert $page) => $page->where('draft', null));
    $this->actingAs($member)->get($root.'?team_id='.$team->id)->assertInertia(fn (Assert $page) => $page->where('draft', null)->where('canEdit', false));
    $this->postJson($root.'/compose?team_id='.$team->id, $payload)->assertForbidden();
    $team->update(['archived_at' => now()]);
    $this->actingAs($owner)->postJson($root.'/compose?team_id='.$team->id, [...$payload, 'version' => 1])->assertConflict();
    DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member->id)->delete();
    $this->actingAs($member)->get($root.'?team_id='.$team->id)->assertNotFound();
    TenderParticipation::query()->create(['user_id' => $owner->id, 'tender_id' => $tender->id, 'deleted_at' => now()]);
    $this->actingAs($owner)->get($root)->assertNotFound();
});
