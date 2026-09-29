<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Enums\QueryStatus;
use App\Models\NotificationDelivery;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Support\Carbon;

final class AccessFreezeService
{
    public function __construct(private readonly AccessService $access) {}

    public function freezeIfInactive(User $user, Carbon $at): void
    {
        if ($this->access->hasActiveAccess($user)) {
            return;
        }

        SearchQuery::query()
            ->where('user_id', $user->id)
            ->where('status', QueryStatus::Active)
            ->update([
                'status' => QueryStatus::Frozen->value,
                'frozen_at' => $at,
                'updated_at' => $at,
            ]);

        NotificationDelivery::query()
            ->where('user_id', $user->id)
            ->where('status', NotificationStatus::Queued)
            ->update([
                'status' => NotificationStatus::Skipped->value,
                'failure_code' => 'access_expired',
                'updated_at' => $at,
            ]);
    }
}
