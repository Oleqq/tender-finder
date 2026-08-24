<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\TenderQueryMatch;

class NotificationService
{
    public function queueForMatch(TenderQueryMatch $match): ?NotificationDelivery
    {
        $match->loadMissing(['searchQuery.user', 'tender']);
        $query = $match->searchQuery;
        $tender = $match->tender;

        if ($query->monitoring_started_at === null || $tender->created_at->lt($query->monitoring_started_at)) {
            return null;
        }

        $user = $query->user;
        $hourStart = now()->startOfHour();
        $hourlyCards = NotificationDelivery::query()
            ->where('user_id', $user->id)
            ->where('type', 'tender_card')
            ->whereIn('status', [NotificationStatus::Queued, NotificationStatus::Sent])
            ->where('created_at', '>=', $hourStart)
            ->count();

        if ($hourlyCards >= 20) {
            $key = 'digest:'.$user->id.':'.$hourStart->format('YmdH');
            $delivery = NotificationDelivery::query()->firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'user_id' => $user->id,
                    'type' => 'tender_digest',
                    'status' => NotificationStatus::Queued,
                    'payload' => ['top_limit' => 10],
                    'scheduled_at' => now(),
                ],
            );
        } else {
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
        }

        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }
}
