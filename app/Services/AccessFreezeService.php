<?php

namespace App\Services;

use App\Enums\AccessState;
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
            ->whereIn('status', [NotificationStatus::Queued, NotificationStatus::Failed])
            ->whereNotIn('type', ['access_granted', 'access_updated', 'access_revoked'])
            ->update([
                'status' => NotificationStatus::Skipped->value,
                'failure_code' => 'access_expired',
                'updated_at' => $at,
            ]);
    }

    public function reconcileCurrentLimit(User $user, Carbon $at): void
    {
        $snapshot = $this->access->snapshotFor($user);
        if (! in_array($snapshot->state, [AccessState::Trialing, AccessState::Active], true)) {
            $this->freezeIfInactive($user, $at);

            return;
        }

        if ($snapshot->activeQueryLimit === null) {
            return;
        }

        $excessIds = SearchQuery::query()
            ->where('user_id', $user->id)
            ->where('status', QueryStatus::Active)
            ->orderBy('id')
            ->limit(PHP_INT_MAX)
            ->skip($snapshot->activeQueryLimit)
            ->pluck('id');

        SearchQuery::query()->whereKey($excessIds)->update([
            'status' => QueryStatus::Frozen->value,
            'frozen_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
