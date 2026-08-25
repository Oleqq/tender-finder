<?php

use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps each server-backed tender feed limited to the signed-in users matches', function () {
    $owner = User::factory()->create(['telegram_id' => '9101']);
    $otherUser = User::factory()->create(['telegram_id' => '9102']);
    $ownerQuery = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Поддержка сайтов',
        'keywords' => ['поддержка', 'сайт'],
        'status' => 'active',
        'monitoring_started_at' => now(),
    ]);
    $otherQuery = SearchQuery::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Чужой мониторинг',
        'keywords' => ['строительство'],
        'status' => 'active',
        'monitoring_started_at' => now(),
    ]);
    $ownerTender = tenderForFeed('feed-owner', 'Поддержка корпоративного сайта');
    $otherTender = tenderForFeed('feed-other', 'Строительство объекта');

    TenderQueryMatch::query()->create([
        'tender_id' => $ownerTender->id,
        'search_query_id' => $ownerQuery->id,
        'match_reasons' => ['keywords' => ['поддержка', 'сайт'], 'region' => 'matched'],
        'matched_at' => now(),
    ]);
    TenderQueryMatch::query()->create([
        'tender_id' => $otherTender->id,
        'search_query_id' => $otherQuery->id,
        'match_reasons' => ['keywords' => ['строительство']],
        'matched_at' => now()->subMinute(),
    ]);

    $this->actingAs($owner)
        ->get('/tenders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenders')
            ->where('mode', 'live')
            ->has('tenderMatches', 1)
            ->where('tenderMatches.0.title', 'Поддержка корпоративного сайта')
            ->where('tenderMatches.0.query_name', 'Поддержка сайтов')
            ->where('tenderMatches.0.match_reasons', ['ключевые слова', 'регион']));
});

it('keeps the anonymous tender page in clearly marked demo mode', function () {
    $this->get('/tenders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenders')
            ->where('mode', 'demo')
            ->where('tenderMatches', []));
});

function tenderForFeed(string $externalId, string $title): Tender
{
    return Tender::query()->create([
        'source' => 'fixture',
        'external_id' => $externalId,
        'canonical_url' => "https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={$externalId}",
        'canonical_url_hash' => hash('sha256', $externalId),
        'title' => $title,
        'description' => 'Синтетическая тестовая запись.',
        'region' => 'Москва',
        'budget_amount' => 250000,
        'deadline_at' => now()->addWeek(),
    ]);
}
