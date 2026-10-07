<?php

use App\Enums\NotificationStatus;
use App\Enums\QueryStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('queues one real daily digest with the user matching tender cards', function () {
    Queue::fake();
    Carbon::setTestNow('2026-09-04 06:00:00 UTC');
    $user = User::factory()->create(['telegram_id' => '91001']);
    Entitlement::query()->create([
        'user_id' => $user->id,
        'code' => 'active_queries',
        'status' => SubscriptionStatus::Active,
        'value' => 3,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDays(2),
    ]);
    NotificationPreference::query()->create([
        'user_id' => $user->id,
        'instant_enabled' => false,
        'digest_enabled' => true,
        'digest_time' => '09:00',
        'timezone' => 'Europe/Moscow',
    ]);
    $query = SearchQuery::query()->create([
        'user_id' => $user->id,
        'name' => 'Разработка сайта',
        'keywords' => ['разработка сайта'],
        'status' => QueryStatus::Active,
        'monitoring_started_at' => now()->subDay(),
    ]);
    $tender = Tender::query()->create([
        'source' => 'fixture',
        'external_id' => 'digest-tender',
        'canonical_url' => 'https://source.example.test/tenders/digest-tender',
        'canonical_url_hash' => hash('sha256', 'digest-tender'),
        'title' => 'Разработка корпоративного сайта',
    ]);
    TenderQueryMatch::query()->create([
        'tender_id' => $tender->id,
        'search_query_id' => $query->id,
        'match_reasons' => ['keywords' => 'matched'],
        'matched_at' => now()->subMinute(),
    ]);

    $makeMatch = function (string $id, string $title, Carbon $matchedAt) use ($query): void {
        $tender = Tender::query()->create([
            'source' => 'fixture',
            'external_id' => $id,
            'canonical_url' => 'https://source.example.test/tenders/'.$id,
            'canonical_url_hash' => hash('sha256', $id),
            'title' => $title,
        ]);
        TenderQueryMatch::query()->create([
            'tender_id' => $tender->id,
            'search_query_id' => $query->id,
            'match_reasons' => ['keywords' => 'matched'],
            'matched_at' => $matchedAt,
        ]);
    };

    $makeMatch('yesterday-afternoon', 'Вчера после дайджеста', now()->subHours(18));
    $makeMatch('too-old', 'До прошлого дайджеста', now()->subDay()->subMinute());
    $makeMatch('next-day', 'После текущего дайджеста', now()->addMinute());
    $makeMatch('expired', 'Истёкший срок приёма', now()->subHours(2));
    Tender::query()->where('external_id', 'expired')->update(['deadline_at' => now()->subMinute()]);

    Artisan::call('notifications:send-due-digests');
    Artisan::call('notifications:send-due-digests');

    $delivery = NotificationDelivery::query()->sole();

    expect($delivery->type)->toBe('tender_digest')
        ->and($delivery->status)->toBe(NotificationStatus::Queued)
        ->and($delivery->payload['count'])->toBe(2)
        ->and(array_column($delivery->payload['tenders'], 'title'))->toContain('Вчера после дайджеста')
        ->not->toContain('До прошлого дайджеста', 'После текущего дайджеста', 'Истёкший срок приёма');
    Queue::assertPushed(DeliverTelegramNotification::class, 1);

    Carbon::setTestNow('2026-09-05 06:00:00 UTC');
    Artisan::call('notifications:send-due-digests');

    $nextDelivery = NotificationDelivery::query()
        ->where('idempotency_key', 'daily-digest:'.$user->id.':20260905')
        ->sole();
    expect($nextDelivery->payload['count'])->toBe(1)
        ->and($nextDelivery->payload['tenders'][0]['title'])->toBe('После текущего дайджеста');
    Queue::assertPushed(DeliverTelegramNotification::class, 2);
});
