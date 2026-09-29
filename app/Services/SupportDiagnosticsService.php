<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\User;

final class SupportDiagnosticsService
{
    public function __construct(
        private readonly AccessService $access,
        private readonly MonitoringStatusService $monitoring,
        private readonly NotificationDeliveryPresenter $deliveries,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $queries = SearchQuery::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get(['id', 'user_id', 'status']);
        $statuses = $this->monitoring->forQueries($queries);

        return [
            'access' => $this->access->snapshotFor($user)->toArray(),
            'monitorings' => $queries->map(fn (SearchQuery $query): array => [
                'id' => $query->id,
                'status' => $query->status->value,
                'sources' => $statuses[$query->id] ?? [],
            ])->values()->all(),
            'deliveries' => $this->deliveries->recentFor($user),
        ];
    }
}
