<?php

use App\Enums\SubscriptionStatus;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use App\Services\TenderMatchingService;
use App\Tenders\RostenderSearchTemplate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('matches deterministic filters with explainable reasons and minus words', function () {
    Queue::fake();
    $user = User::factory()->create(['telegram_id' => '9001']);
    $query = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Поддержка сайтов',
        'keywords' => ['поддержка', 'сайт'],
        'minus_keywords' => ['строительство'],
        'region' => 'Москва',
        'status' => 'active',
        'monitoring_started_at' => now(),
    ]);
    $tender = Tender::query()->create([
        'source' => 'fixture',
        'external_id' => 'fixture-1',
        'canonical_url' => 'https://source.example.test/tenders/fixture-1',
        'canonical_url_hash' => hash('sha256', 'fixture-1'),
        'title' => 'Техническая поддержка сайта',
        'region' => 'Москва',
    ]);

    $result = app(TenderMatchingService::class)->evaluate($query, $tender);
    expect($result->matches)->toBeTrue()
        ->and($result->reasons['region'])->toBe('matched')
        ->and($result->reasons['rule_score'])->toBe(60);

    app(TenderMatchingService::class)->matchTender($tender);
    expect(TenderQueryMatch::query()->count())->toBe(1);

    $tender->forceFill(['title' => 'Строительство и поддержка сайта'])->save();
    expect(app(TenderMatchingService::class)->evaluate($query, $tender)->matches)->toBeFalse();
});

it('scores partial any-word matches without changing the deterministic match decision', function () {
    $query = new SearchQuery([
        'keywords' => ['поддержка', 'сайта'],
        'filters' => ['relevance' => ['match_mode' => 'any']],
    ]);
    $tender = new Tender(['title' => 'Техническая поддержка серверов']);

    $result = app(TenderMatchingService::class)->evaluate($query, $tender);

    expect($result->matches)->toBeTrue()
        ->and($result->reasons['rule_score'])->toBe(20);
});

it('uses the saved any-word and exact-phrase matching modes', function () {
    $query = new SearchQuery([
        'keywords' => ['поддержка', 'сайта'],
        'minus_keywords' => [],
        'filters' => ['relevance' => ['match_mode' => 'any']],
    ]);
    $tender = new Tender(['title' => 'Техническая поддержка серверов']);
    $matcher = app(TenderMatchingService::class);

    expect($matcher->evaluate($query, $tender)->matches)->toBeTrue();

    $query->filters = ['relevance' => ['match_mode' => 'exact']];
    expect($matcher->evaluate($query, $tender)->matches)->toBeFalse();

    $tender->title = 'Техническая поддержка сайта';
    expect($matcher->evaluate($query, $tender)->matches)->toBeTrue();
});

it('enforces the server-side three active query limit', function () {
    config()->set([
        'tender.rostender.enabled' => true,
        'tender.rostender.public_distribution_approved' => true,
        'tender.rostender.api_key' => 'test',
        'tender.rostender.basic_active_monitor_limit' => 10,
    ]);
    Cache::put('rostender:search-templates:v1', [new RostenderSearchTemplate(42, 'Серверы')], 60);
    $user = User::factory()->create(['telegram_id' => '9002']);
    $plan = app(PlanCatalog::class)->basic();
    Entitlement::query()->create([
        'user_id' => $user->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'plan_id' => $plan->id,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
    ]);

    foreach (range(1, 3) as $number) {
        $this->actingAs($user)->postJson('/queries', [
            'keywords' => ["слово {$number}"],
            'filters' => ['source' => ['rostender_template_id' => 42]],
        ])->assertCreated();
    }

    $this->actingAs($user)->postJson('/queries', [
        'keywords' => ['четвёртый'],
        'filters' => ['source' => ['rostender_template_id' => 42]],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');
});

it('lets an owner update or delete a saved query without exposing it to another user', function () {
    config()->set([
        'tender.rostender.enabled' => true,
        'tender.rostender.public_distribution_approved' => true,
        'tender.rostender.api_key' => 'test',
    ]);
    Cache::put('rostender:search-templates:v1', [new RostenderSearchTemplate(42, 'Серверы')], 60);
    $owner = User::factory()->create(['telegram_id' => '9003']);
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Старый мониторинг',
        'keywords' => ['сайт'],
        'status' => 'paused',
    ]);

    $this->actingAs($owner)
        ->patchJson("/queries/{$query->id}", [
            'name' => 'Поддержка сайтов',
            'keywords' => ['поддержка', 'сайт'],
            'minus_keywords' => ['строительство'],
            'region' => 'Москва',
            'budget_min' => 100000,
            'budget_max' => 300000,
            'deadline_from' => '2026-09-01',
            'deadline_to' => '2026-09-30',
            'filters' => ['source' => ['rostender_template_id' => 42]],
        ])
        ->assertOk()
        ->assertJsonPath('query.name', 'Поддержка сайтов')
        ->assertJsonPath('query.region', 'Москва')
        ->assertJsonPath('query.status', 'paused');

    $updated = $query->fresh();
    expect($updated->keywords)->toBe(['поддержка', 'сайт'])
        ->and($updated->minus_keywords)->toBe(['строительство'])
        ->and($updated->budget_min)->toBe('100000.00');

    $otherUser = User::factory()->create(['telegram_id' => '9004']);
    $this->actingAs($otherUser)
        ->patchJson("/queries/{$query->id}", ['keywords' => ['чужой']])
        ->assertNotFound();

    $this->actingAs($owner)
        ->deleteJson("/queries/{$query->id}")
        ->assertNoContent();

    expect($query->fresh()->status->value)->toBe('deleted');
});
