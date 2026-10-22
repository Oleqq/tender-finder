<?php

use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderChange;
use App\Models\TenderParticipation;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\TenderAnalyticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set(['tender.local_mvp_full_access.enabled' => false, 'tender.local_mvp_operator.enabled' => false]);
    Http::preventStrayRequests();
    $this->travelTo(now()->setTimezone('Europe/Moscow')->setDate(2026, 10, 22)->setTime(12, 0));
});

it('enforces Basic Pro and Ultra payloads on detail and work pages regardless of request parameters', function (string $tier) {
    [$user, $query, $tender] = analyticsFixture($tier);
    foreach (['', '/work'] as $suffix) {
        $this->actingAs($user)->get('/tenders/'.$tender->id.$suffix.'?plan=ultra&tier=ultra')->assertOk()
            ->assertInertia(function (Assert $page) use ($tier): void {
                $page->where('tender.analytics.tier', $tier)->has('tender.summary');
                if ($tier === 'basic') {
                    $page->missing('tender.analytics.pro')->missing('tender.analytics.ultra');
                } else {
                    $page->has('tender.analytics.pro.checks', 8)->has('tender.analytics.pro.actions');
                    $tier === 'ultra' ? $page->has('tender.analytics.ultra.comparison') : $page->missing('tender.analytics.ultra');
                }
            });
    }
    Http::assertNothingSent();
})->with(['basic', 'pro', 'ultra']);

it('rechecks active entitlement after downgrade and never reveals another users tender', function () {
    [$user, , $tender] = analyticsFixture('ultra');
    $this->actingAs($user)->get('/tenders/'.$tender->id)->assertInertia(fn (Assert $p) => $p->has('tender.analytics.ultra'));
    Entitlement::query()->where('user_id', $user->id)->update(['plan_id' => app(PlanCatalog::class)->basic()->id]);
    $this->get('/tenders/'.$tender->id)->assertInertia(fn (Assert $p) => $p->where('tender.analytics.tier', 'basic')->missing('tender.analytics.pro')->missing('tender.analytics.ultra'));
    $other = User::factory()->create();
    $this->actingAs($other)->get('/tenders/'.$tender->id)->assertNotFound();
    $this->get('/tenders/'.$tender->id.'/work')->assertNotFound();
});

it('grants full analytics to owners and local full access while expired and unknown plans stay Basic', function () {
    [$user] = analyticsFixture('ultra');
    Entitlement::query()->where('user_id', $user->id)->update(['ends_at' => now()]);
    $service = app(TenderAnalyticsService::class);
    expect($service->tierFor($user))->toBe('basic');
    $user->update(['role' => UserRole::SuperAdmin]);
    expect($service->tierFor($user->refresh()))->toBe('ultra');
    $normal = User::factory()->create();
    config()->set('tender.local_mvp_full_access.enabled', true);
    expect($service->tierFor($normal))->toBe('ultra');
});

it('does not read premium comparison history or participation tables for Basic', function () {
    [$user, , $tender] = analyticsFixture('basic');
    $tables = [];
    DB::listen(function ($query) use (&$tables): void {
        $tables[] = $query->sql;
    });
    $result = app(TenderAnalyticsService::class)->forTender($user, $tender);
    expect(array_keys($result))->toBe(['tier', 'generated_at']);
    expect(implode(' ', $tables))->not->toContain('tender_changes', 'tender_participations', 'tender_query_matches');
});

it('handles absent contradictory future and elapsed dates without inventing a preparation window', function () {
    [$user, , $tender] = analyticsFixture('pro');
    $service = app(TenderAnalyticsService::class);
    $tender->update(['published_at' => null, 'deadline_at' => null, 'budget_amount' => null]);
    $pro = $service->forTender($user, $tender->refresh())['pro'];
    expect($pro['priority'])->toBe('unknown')->and($pro['window_hours'])->toBeNull()->and($pro['buffer_at'])->toBeNull();
    $tender->update(['published_at' => now(), 'deadline_at' => now()]);
    expect($service->forTender($user, $tender->refresh())['pro']['priority'])->toBe('conflict');
    $tender->update(['published_at' => now()->addDay(), 'deadline_at' => now()->addDays(2)]);
    expect($service->forTender($user, $tender->refresh())['pro']['window_hours'])->toBeNull();
    $tender->update(['published_at' => now()->subDays(2), 'deadline_at' => now()->subHour()]);
    expect($service->forTender($user, $tender->refresh())['pro']['priority'])->toBe('elapsed');
    $tender->update(['deadline_at' => now()->addHours(12)]);
    expect($service->forTender($user, $tender->refresh())['pro']['priority'])->toBe('urgent');
    $tender->update(['deadline_at' => now()->addHours(48)]);
    $pro = $service->forTender($user, $tender->refresh())['pro'];
    expect($pro['priority'])->toBe('soon')->and($pro['buffer_at'])->toBe(now()->addDay()->toAtomString());
});

it('computes quartiles from deduplicated same-monitoring budgets and excludes unrelated private or stale cards', function () {
    [$user, $query, $tender] = analyticsFixture('ultra');
    foreach ([100, 200, 300] as $price) {
        analyticsTender($query, 'price-'.$price, (string) $price);
    }
    analyticsTender($query, 'duplicate', '100')->update(['reg_number' => 'price-100']);
    analyticsTender($query, 'current-duplicate', '900')->update(['reg_number' => $tender->reg_number]);
    analyticsTender($query, 'other-currency', '900')->update(['currency' => 'USD']);
    analyticsTender($query, 'unrelated', '900')->update(['title' => 'Поставка бетонных плит']);
    analyticsTender($query, 'stale', '900')->update(['published_at' => now()->subDays(91)]);
    analyticsTender($query, 'future', '900')->update(['published_at' => now()->addDay()]);
    analyticsTender($query, 'zero', '0');
    [, $privateQuery] = analyticsFixture('ultra');
    analyticsTender($privateQuery, 'private', '900');
    $unrelatedQuery = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Другой мониторинг', 'keywords' => ['сайт'], 'status' => 'active']);
    analyticsTender($unrelatedQuery, 'not-in-current-monitoring', '900');
    $comparison = app(TenderAnalyticsService::class)->forTender($user, $tender)['ultra']['comparison'];
    expect($comparison['sample_count'])->toBe(3)
        ->and($comparison['median'])->toBe('200.00')
        ->and($comparison['lower_quartile'])->toBe('150.00')
        ->and($comparison['upper_quartile'])->toBe('250.00')
        ->and($comparison['difference_percent'])->toBe(150.0);
    Http::assertNothingSent();
});

it('suppresses statistical conclusions for fewer than three samples and never treats missing price as zero', function () {
    [$user, $query, $tender] = analyticsFixture('ultra');
    analyticsTender($query, 'one', '100');
    analyticsTender($query, 'two', '200');
    $service = app(TenderAnalyticsService::class);
    $comparison = $service->forTender($user, $tender)['ultra']['comparison'];
    expect($comparison['sample_count'])->toBe(2)->and($comparison['median'])->toBeNull()->and($comparison['difference_percent'])->toBeNull();
    analyticsTender($query, 'three', '300');
    $tender->update(['budget_amount' => null]);
    $comparison = $service->forTender($user, $tender->refresh())['ultra']['comparison'];
    expect($comparison['median'])->toBe('200.00')->and($comparison['difference_percent'])->toBeNull();
});

it('keeps personal readiness separate from other users and exposes only whitelisted change facts', function () {
    [$user, , $tender] = analyticsFixture('ultra');
    TenderChange::query()->create(['tender_id' => $tender->id, 'changes' => [
        'budget_amount' => ['before' => '400.00', 'after' => '500.00'],
        'secret' => ['before' => 'private', 'after' => 'private'],
        'stage' => ['before' => ['secret'], 'after' => 'bad'],
    ]]);
    $mine = TenderParticipation::query()->create(['user_id' => $user->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    $mine->items()->create(['title' => 'Готово', 'completed_at' => now()]);
    $mine->items()->create(['title' => 'Просрочено', 'due_on' => now()->subDay()]);
    $other = User::factory()->create();
    $private = TenderParticipation::query()->create(['user_id' => $other->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    $private->items()->create(['title' => 'Не показывать', 'due_on' => now()->subDays(2)]);
    $ultra = app(TenderAnalyticsService::class)->forTender($user, $tender)['ultra'];
    expect($ultra['readiness']['tasks_total'])->toBe(2)->and($ultra['readiness']['tasks_completed'])->toBe(1)
        ->and($ultra['readiness']['tasks_overdue'])->toBe(1)->and($ultra['readiness']['completion_percent'])->toBe(50)
        ->and($ultra['changes']['items'][0]['fields'])->toHaveCount(1)
        ->and($ultra['changes']['items'][0]['fields'][0]['field'])->toBe('budget_amount');
});

it('uses the viewers own tier and only the selected teams shared monitoring and checklist', function () {
    [$owner, $query, $tender] = analyticsFixture('ultra');
    [$member] = analyticsFixture('basic');
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Аналитика команды']);
    foreach ([$owner->id => 'owner', $member->id => 'member'] as $id => $role) {
        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $id, 'role' => $role]);
    }
    DB::table('team_search_queries')->insert(['team_id' => $team->id, 'search_query_id' => $query->id, 'shared_by_id' => $owner->id]);
    TenderParticipation::query()->create(['user_id' => $owner->id, 'team_id' => $team->id, 'tender_id' => $tender->id, 'stage' => 'preparing']);
    $this->actingAs($member)->get('/tenders/'.$tender->id.'/work?team_id='.$team->id)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('tender.analytics.tier', 'basic')->missing('tender.analytics.ultra'));
    Entitlement::query()->where('user_id', $member->id)->update(['plan_id' => app(PlanCatalog::class)->ultra()->id]);
    analyticsTender($query, 'shared', '100');
    [, $privateQuery] = analyticsFixture('ultra');
    analyticsTender($privateQuery, 'hidden', '900');
    $this->get('/tenders/'.$tender->id.'/work?team_id='.$team->id)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('tender.analytics.ultra.comparison.sample_count', 1)
        ->where('tender.analytics.ultra.readiness.scope', 'team')
        ->where('tender.analytics.ultra.readiness.tasks_total', 0));
});

function analyticsFixture(string $tier): array
{
    $user = User::factory()->create();
    Entitlement::query()->create(['user_id' => $user->id, 'plan_id' => app(PlanCatalog::class)->byCode($tier)->id,
        'code' => 'active_queries', 'status' => 'active', 'value' => 3, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    $query = SearchQuery::query()->create(['user_id' => $user->id, 'name' => 'Сайты', 'keywords' => ['сайт'], 'status' => 'active']);

    return [$user, $query, analyticsTender($query, 'target-'.$user->id, '500')];
}

function analyticsTender(SearchQuery $query, string $key, string $price): Tender
{
    $tender = Tender::query()->create(['source' => 'workspace_ru', 'external_id' => $key, 'reg_number' => $key,
        'canonical_url' => 'https://example.test/analytics/'.$key, 'canonical_url_hash' => hash('sha256', $key),
        'title' => 'Разработка сайта заказчика', 'currency' => 'RUB', 'budget_amount' => $price,
        'published_at' => now()->subDay(), 'deadline_at' => now()->addDays(5)]);
    TenderQueryMatch::query()->create(['search_query_id' => $query->id, 'tender_id' => $tender->id, 'matched_at' => now(), 'match_reasons' => []]);

    return $tender;
}
