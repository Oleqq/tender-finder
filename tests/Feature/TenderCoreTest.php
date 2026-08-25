<?php

use App\Enums\SubscriptionStatus;
use App\Jobs\MatchTender;
use App\Models\Entitlement;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\RssFixtureImportService;
use App\Services\TenderMatchingService;
use App\Tenders\EisRssSource;
use App\Tenders\RssSourceException;
use Illuminate\Support\Facades\Queue;

it('imports synthetic RSS safely, deduplicates reg numbers, and keeps first poll silent', function () {
    Queue::fake();
    $feed = SourceFeed::query()->create([
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/extendedsearch/rss.html?searchString=site',
        'url_hash' => hash('sha256', 'fixture-feed'),
        'status' => 'active',
        'poll_interval_seconds' => 600,
    ]);
    $source = app(EisRssSource::class);
    $importer = app(RssFixtureImportService::class);

    $first = $importer->import($feed, $source->parse(file_get_contents(base_path('tests/Fixtures/eis-rss-initial.xml'))));
    expect($first->items_created)->toBe(1)
        ->and(Tender::query()->count())->toBe(1);
    Queue::assertNothingPushed();

    $second = $importer->import($feed->fresh(), $source->parse(file_get_contents(base_path('tests/Fixtures/eis-rss-next.xml'))));
    expect($second->items_created)->toBe(1)
        ->and(Tender::query()->count())->toBe(2);
    Queue::assertPushed(MatchTender::class, 1);
});

it('rejects HTML and untrusted RSS links before storing anything', function () {
    $source = app(EisRssSource::class);

    expect(fn () => $source->parse('<html><body>not RSS</body></html>'))
        ->toThrow(RssSourceException::class);
    expect(fn () => $source->parse('<rss><channel><item><title>x</title><link>https://evil.example/epz/order/x</link></item></channel></rss>'))
        ->toThrow(RssSourceException::class);
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
        'canonical_url' => 'https://zakupki.gov.ru/epz/order/notice/test',
        'canonical_url_hash' => hash('sha256', 'fixture-1'),
        'title' => 'Техническая поддержка сайта',
        'region' => 'Москва',
    ]);

    $result = app(TenderMatchingService::class)->evaluate($query, $tender);
    expect($result->matches)->toBeTrue()
        ->and($result->reasons['region'])->toBe('matched');

    app(TenderMatchingService::class)->matchTender($tender);
    expect(TenderQueryMatch::query()->count())->toBe(1);

    $tender->forceFill(['title' => 'Строительство и поддержка сайта'])->save();
    expect(app(TenderMatchingService::class)->evaluate($query, $tender)->matches)->toBeFalse();
});

it('enforces the server-side three active query limit', function () {
    $user = User::factory()->create(['telegram_id' => '9002']);
    Entitlement::query()->create([
        'user_id' => $user->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
    ]);

    foreach (range(1, 3) as $number) {
        $this->actingAs($user)->postJson('/queries', ['keywords' => ["слово {$number}"]])->assertCreated();
    }

    $this->actingAs($user)->postJson('/queries', ['keywords' => ['четвёртый']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('limit');
});

it('lets an owner update or delete a saved query without exposing it to another user', function () {
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
