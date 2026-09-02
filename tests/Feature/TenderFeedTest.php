<?php

use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
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
            ->where('tenderMatches.total', 1)
            ->has('tenderMatches.data', 1)
            ->where('tenderMatches.data.0.title', 'Поддержка корпоративного сайта')
            ->where('tenderMatches.data.0.query_name', 'Поддержка сайтов')
            ->where('tenderMatches.data.0.status', 'new')
            ->where('tenderMatches.data.0.match_reasons', ['ключевые слова', 'регион']));
});

it('filters sorts and paginates the signed-in users tender feed', function () {
    $owner = User::factory()->create(['telegram_id' => '9201']);
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Сайты',
        'keywords' => ['сайт'],
        'status' => 'active',
        'monitoring_started_at' => now(),
    ]);

    foreach (range(1, 13) as $index) {
        $tender = tenderForFeed(
            'page-'.$index,
            $index === 13 ? 'Особый корпоративный портал' : 'Обычный сайт '.$index,
            [
                'budget_amount' => $index * 1000,
                'metadata' => ['customer' => $index === 13 ? 'Customer Alpha' : 'Другой заказчик'],
            ],
        );
        TenderQueryMatch::query()->create([
            'tender_id' => $tender->id,
            'search_query_id' => $query->id,
            'match_reasons' => ['keywords' => ['сайт']],
            'matched_at' => now()->subMinutes($index),
        ]);

        if ($index === 13) {
            TenderUserState::query()->create([
                'user_id' => $owner->id,
                'tender_id' => $tender->id,
                'status' => 'favorite',
                'tags' => ['приоритет'],
                'next_action_on' => now()->addDay(),
            ]);
        }
    }

    $this->actingAs($owner)
        ->get('/tenders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenderMatches.total', 13)
            ->where('tenderMatches.last_page', 2)
            ->has('tenderMatches.data', 12));

    $this->actingAs($owner)
        ->get('/tenders?q=alpha&status=favorite&tag=приоритет&query_id='.$query->id.'&sort=budget_desc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'alpha')
            ->where('filters.status', 'favorite')
            ->where('filters.tag', 'приоритет')
            ->where('filters.query_id', $query->id)
            ->where('filters.sort', 'budget_desc')
            ->where('tenderMatches.total', 1)
            ->where('tenderMatches.data.0.title', 'Особый корпоративный портал')
            ->where('tenderMatches.data.0.status', 'favorite')
            ->where('tenderMatches.data.0.tags', ['приоритет']));
});

it('keeps the tender feed behind the authenticated user journey', function () {
    $this->get('/tenders')->assertRedirect('/onboarding');
});

it('updates personal tender fields inline only for the matching user', function () {
    $owner = User::factory()->create(['telegram_id' => '9401']);
    $other = User::factory()->create(['telegram_id' => '9402']);
    $query = SearchQuery::query()->create([
        'user_id' => $owner->id,
        'name' => 'Inline',
        'keywords' => ['сайт'],
        'status' => 'active',
    ]);
    $tender = tenderForFeed('inline-state', 'Inline-карточка');
    TenderQueryMatch::query()->create([
        'tender_id' => $tender->id,
        'search_query_id' => $query->id,
        'match_reasons' => ['keywords' => ['сайт']],
        'matched_at' => now(),
    ]);

    $this->actingAs($owner)
        ->patchJson('/tenders/'.$tender->id.'/state', [
            'status' => 'favorite',
            'tags' => [' Срочно ', 'проверить', 'СРОЧНО'],
            'next_action_on' => '2026-09-09',
        ])
        ->assertOk()
        ->assertJsonPath('state.status', 'favorite')
        ->assertJsonPath('state.tags.0', 'СРОЧНО')
        ->assertJsonPath('state.tags.1', 'проверить')
        ->assertJsonPath('state.next_action_on', '2026-09-09');

    $this->actingAs($other)
        ->patchJson('/tenders/'.$tender->id.'/state', [
            'status' => 'dismissed',
        ])
        ->assertNotFound();

    $this->assertDatabaseHas('tender_user_states', [
        'user_id' => $owner->id,
        'tender_id' => $tender->id,
        'status' => 'favorite',
    ]);
    $this->assertDatabaseMissing('tender_user_states', [
        'user_id' => $other->id,
        'tender_id' => $tender->id,
    ]);
});

/** @param array<string, mixed> $attributes */
function tenderForFeed(string $externalId, string $title, array $attributes = []): Tender
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
        ...$attributes,
    ]);
}
