<?php

use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceFeedSearchQuery;
use App\Models\User;

it('does not expose the retired EIS search parsing or enrichment endpoints', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/local/mvp/eis-rss-preview')->assertNotFound();
    $this->get('/local/mvp/eis/okpd2-options')->assertNotFound();
    $this->postJson('/local/mvp/tenders/1/enrich')->assertNotFound();
    $this->get('/mvp/workspace')->assertNotFound();
});

it('pauses legacy EIS feeds and source-only monitorings without deleting history', function () {
    $user = User::factory()->create();
    $legacyQuery = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Архивный мониторинг',
        'keywords' => ['сервер'],
        'status' => 'active',
    ]);
    $mixedQuery = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Мониторинг с RosTender',
        'keywords' => ['сайт'],
        'status' => 'active',
    ]);
    $legacyFeed = SourceFeed::query()->create([
        'source' => 'eis_rss',
        'canonical_url' => 'https://retired-source.invalid/feed',
        'url_hash' => hash('sha256', 'retired-source'),
        'status' => 'active',
        'poll_interval_seconds' => 600,
        'next_poll_at' => now(),
    ]);
    $rostenderFeed = SourceFeed::query()->create([
        'source' => 'rostender',
        'source_identifier' => 42,
        'canonical_url' => 'https://rostender.info/api/tenders/get/template/42',
        'url_hash' => hash('sha256', 'rostender-template-42'),
        'status' => 'active',
        'poll_interval_seconds' => 3600,
        'next_poll_at' => now(),
    ]);

    foreach ([$legacyQuery, $mixedQuery] as $query) {
        SourceFeedSearchQuery::query()->create([
            'source_feed_id' => $legacyFeed->id,
            'search_query_id' => $query->id,
        ]);
    }
    RostenderFeedSearchQuery::query()->create([
        'source_feed_id' => $rostenderFeed->id,
        'search_query_id' => $mixedQuery->id,
    ]);

    $migration = require database_path('migrations/2026_09_26_120000_retire_eis_source.php');
    $migration->up();

    expect($legacyFeed->fresh()->status)->toBe('paused')
        ->and($legacyFeed->fresh()->next_poll_at)->toBeNull()
        ->and($legacyQuery->fresh()->status->value)->toBe('paused')
        ->and($mixedQuery->fresh()->status->value)->toBe('active')
        ->and(SourceFeedSearchQuery::query()->count())->toBe(2);
});
