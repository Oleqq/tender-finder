<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\TenderQueryMatch;

class NotificationService
{
    public function __construct(private readonly AccessService $access) {}

    public function queueForMatch(TenderQueryMatch $match): ?NotificationDelivery
    {
        $match->loadMissing(['searchQuery.user', 'tender']);
        $query = $match->searchQuery;
        $tender = $match->tender;

        if ($query->monitoring_started_at === null || $tender->created_at->lt($query->monitoring_started_at)) {
            return null;
        }

        $user = $query->user;

        if (! $this->access->hasActiveAccess($user)) {
            return null;
        }

        $preference = NotificationPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'instant_enabled' => true,
                'digest_enabled' => true,
                'digest_time' => '09:00',
                'timezone' => 'Europe/Moscow',
            ],
        );

        if (! $preference->instant_enabled) {
            return null;
        }

        $hourStart = now()->startOfHour();
        $hourlyCards = NotificationDelivery::query()
            ->where('user_id', $user->id)
            ->where('type', 'tender_card')
            ->whereIn('status', [NotificationStatus::Queued, NotificationStatus::Sent])
            ->where('created_at', '>=', $hourStart)
            ->count();

        if ($hourlyCards >= 20) {
            return null;
        }

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => 'tender-match:'.$match->id],
            [
                'user_id' => $user->id,
                'tender_id' => $tender->id,
                'search_query_id' => $query->id,
                'type' => 'tender_card',
                'status' => NotificationStatus::Queued,
                'payload' => [
                    'title' => mb_substr($tender->title, 0, 500),
                    'url' => $tender->canonical_url,
                ],
                'scheduled_at' => now(),
            ],
        );

        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }
}
