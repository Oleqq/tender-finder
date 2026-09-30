<?php

use App\Jobs\PollRostenderTemplate;
use App\Models\Entitlement;
use App\Models\RostenderApiUsage;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedItem;
use App\Models\SourceRun;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use App\Services\PlanCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set([
        'tender.rostender.enabled' => true,
        'tender.rostender.public_distribution_approved' => true,
        'tender.rostender.basic_active_monitor_limit' => 3,
        'tender.rostender.basic_poll_interval_seconds' => 3600,
    ]);
});

it('requires active access and an approved source before creating a quick monitoring', function () {
    discoveryFeed();
    $guest = User::factory()->create();
    $this->actingAs($guest)->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertUnprocessable();
    config()->set('tender.rostender.public_distribution_approved', false);
    $this->actingAs(discoveryUser())->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertUnprocessable();
    expect(SearchQuery::query()->count())->toBe(0);
});

it('starts an individual monitoring, shows current matches and reports a delayed source without API calls', function () {
    $user = discoveryUser();
    $feed = discoveryFeed();
    $matching = discoveryTender('Разработка сайта для заказчика', 'match-1', $feed);
    discoveryTender('Поставка мебели', 'other-1', $feed);
    discoveryTender('Разработка сайта с истёкшим сроком', 'expired-1', $feed)
        ->forceFill(['deadline_at' => now()->subDay()])->save();
    $feed->forceFill([
        'last_success_at' => now()->subDays(14),
        'last_error_code' => 'quota_exhausted',
        'next_poll_at' => now()->addDay(),
    ])->save();
    SourceRun::query()->create([
        'source_feed_id' => $feed->id,
        'source' => 'rostender',
        'status' => 'failed',
        'started_at' => now(),
        'finished_at' => now(),
        'error_code' => 'quota_exhausted',
    ]);
    Http::fake();

    RostenderApiUsage::query()->create([
        'usage_date' => now('Europe/Moscow')->toDateString(),
        'successful_requests' => 200,
        'in_flight_requests' => 0,
    ]);

    $this->actingAs($user)->get('/queries')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rostenderTemplates.0.id', 42));

    $queryId = $this->actingAs($user)->postJson('/queries/quick', [
        'phrase' => 'Разработка сайта',
    ])->assertCreated()->assertJsonPath('cached_matches', 1)->json('query.id');

    expect(SearchQuery::query()->findOrFail($queryId)->status->value)->toBe('active')
        ->and(TenderQueryMatch::query()->where('search_query_id', $queryId)->where('tender_id', $matching->id)->exists())->toBeTrue();
    Http::assertNothingSent();

    $this->actingAs($user)->get('/tenders?query_id='.$queryId.'&started=1')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenderMatches.total', 1)
            ->where('searchStarted', true)
            ->where('monitoringStatus.state', 'error'));

    $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertOk()->assertJsonPath('reused', true);
    expect(SearchQuery::query()->count())->toBe(1);
});

it('rejects ambiguous templates instead of silently searching only one of them', function () {
    $user = discoveryUser();
    discoveryFeed();
    SourceFeed::query()->create([
        'source' => 'rostender',
        'source_identifier' => 43,
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/43',
        'url_hash' => hash('sha256', 'rostender:template:43'),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
    ]);
    $this->actingAs($user)->postJson('/queries/quick', ['phrase' => 'Разработка сайта'])
        ->assertUnprocessable();
    expect(SearchQuery::query()->count())->toBe(0);
});

it('queues a due source check when the first search is started', function () {
    Queue::fake();
    $user = discoveryUser();
    $feed = discoveryFeed();

    $this->actingAs($user)->postJson('/queries/quick', [
        'phrase' => 'Разработка сайта',
    ])->assertCreated()->assertJsonPath('check_queued', true);

    Queue::assertPushed(PollRostenderTemplate::class, fn (PollRostenderTemplate $job): bool => $job->feedId === $feed->id);
});

function discoveryUser(): User
{
    $user = User::factory()->create();
    $plan = app(PlanCatalog::class)->basic();
    Entitlement::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'code' => 'active_queries',
        'status' => 'active',
        'value' => 3,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
    ]);

    return $user;
}

function discoveryFeed(): SourceFeed
{
    return SourceFeed::query()->firstOrCreate([
        'source' => 'rostender', 'source_identifier' => 42,
    ], [
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/42',
        'url_hash' => hash('sha256', 'rostender:template:42'),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
        'next_poll_at' => now(),
    ]);
}

function discoveryTender(string $title, string $externalId, ?SourceFeed $feed = null): Tender
{
    $feed ??= discoveryFeed();
    $item = SourceFeedItem::query()->create([
        'source_feed_id' => $feed->id,
        'external_id' => $externalId,
        'canonical_url' => 'https://rostender.info/tender/'.$externalId,
        'url_hash' => hash('sha256', $externalId),
        'content_hash' => hash('sha256', $title),
        'discovered_at' => now(),
        'title' => $title,
    ]);

    return Tender::query()->create([
        'source' => 'rostender',
        'external_id' => $externalId,
        'source_feed_item_id' => $item->id,
        'canonical_url' => $item->canonical_url,
        'canonical_url_hash' => $item->url_hash,
        'title' => $title,
        'currency' => 'RUB',
    ]);
}
