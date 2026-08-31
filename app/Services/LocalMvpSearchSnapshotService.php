<?php

namespace App\Services;

use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;
use App\Models\User;

final class LocalMvpSearchSnapshotService
{
    /** @param list<int> $tenderIds */
    public function remember(
        User $user,
        string $query,
        LocalMvpEisRssImportResult $result,
        array $tenderIds,
        ?SearchQuery $savedQuery = null,
    ): LocalMvpSearchSnapshot {
        /** @var LocalMvpSearchSnapshot $snapshot */
        $snapshot = LocalMvpSearchSnapshot::query()->create([
            'user_id' => $user->id,
            'search_query_id' => $savedQuery?->id,
            'query' => $query,
            'source' => 'eis_rss',
            'tender_ids' => array_values(array_unique($tenderIds)),
            'items_seen' => $result->itemsSeen,
            'items_matched' => $result->itemsMatched,
            'items_created' => $result->itemsCreated,
            'pages_requested' => $result->pagesRequested,
            'pages_loaded' => $result->pagesLoaded,
            'partially_loaded' => $result->partiallyLoaded,
        ]);

        return $snapshot;
    }

    public function currentFor(User $user): ?LocalMvpSearchSnapshot
    {
        return LocalMvpSearchSnapshot::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    /** @return list<int> */
    public function historyTenderIdsFor(User $user): array
    {
        return LocalMvpSearchSnapshot::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get(['tender_ids'])
            ->flatMap(function (LocalMvpSearchSnapshot $snapshot): array {
                return array_values(array_filter(
                    $snapshot->tender_ids,
                    fn (int $id): bool => $id > 0,
                ));
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function accessibleTenderIdsFor(User $user): array
    {
        return LocalMvpSearchSnapshot::query()
            ->where('user_id', $user->id)
            ->get(['tender_ids'])
            ->flatMap(function (LocalMvpSearchSnapshot $snapshot): array {
                return array_values(array_filter(
                    $snapshot->tender_ids,
                    fn (int $id): bool => $id > 0,
                ));
            })
            ->unique()
            ->values()
            ->all();
    }
}
