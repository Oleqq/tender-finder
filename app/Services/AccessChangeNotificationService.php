<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\Entitlement;
use App\Models\NotificationDelivery;

final class AccessChangeNotificationService
{
    public function granted(Entitlement $entitlement, int $eventId): NotificationDelivery
    {
        return $this->queue($entitlement, $eventId, 'access_granted');
    }

    public function updated(Entitlement $entitlement, int $eventId): NotificationDelivery
    {
        return $this->queue($entitlement, $eventId, 'access_updated');
    }

    public function revoked(Entitlement $entitlement, int $eventId): NotificationDelivery
    {
        return $this->queue($entitlement, $eventId, 'access_revoked');
    }

    private function queue(Entitlement $entitlement, int $eventId, string $type): NotificationDelivery
    {
        $entitlement->loadMissing('plan');

        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => "access-change:{$eventId}"],
            [
                'user_id' => $entitlement->user_id,
                'type' => $type,
                'status' => NotificationStatus::Queued,
                'payload' => [
                    'entitlement_id' => $entitlement->id,
                    'plan_code' => $entitlement->plan?->code,
                    'ends_at' => $entitlement->ends_at?->toAtomString(),
                    'developer_mode' => ($entitlement->metadata['developer_mode'] ?? false) === true,
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
