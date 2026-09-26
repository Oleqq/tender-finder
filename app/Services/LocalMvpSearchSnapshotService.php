<?php

namespace App\Services;

use App\Models\LocalMvpSearchSnapshot;
use App\Models\User;

final class LocalMvpSearchSnapshotService
{
    /**
     * @return array<int, array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}>
     */
    public function matchReasonsFor(?LocalMvpSearchSnapshot $snapshot): array
    {
        $relevance = $snapshot?->relevance;
        $rawReasons = is_array($relevance) ? ($relevance['match_reasons'] ?? null) : null;

        if (! is_array($rawReasons)) {
            return [];
        }

        $reasons = [];

        foreach ($rawReasons as $tenderId => $reason) {
            if (is_numeric($tenderId) && is_array($reason)) {
                $reasons[(int) $tenderId] = $reason;
            }
        }

        return $reasons;
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

    /**
     * @return array<int, array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}>
     */
    public function historyMatchReasonsFor(User $user): array
    {
        $reasons = [];

        LocalMvpSearchSnapshot::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get(['relevance'])
            ->each(function (LocalMvpSearchSnapshot $snapshot) use (&$reasons): void {
                foreach ($this->matchReasonsFor($snapshot) as $tenderId => $reason) {
                    $reasons[$tenderId] ??= $reason;
                }
            });

        return $reasons;
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

    /** @return list<int> */
    public function newTenderIdsFor(LocalMvpSearchSnapshot $snapshot): array
    {
        if ($snapshot->search_query_id === null) {
            return $snapshot->tender_ids;
        }

        $previous = LocalMvpSearchSnapshot::query()
            ->where('user_id', $snapshot->user_id)
            ->where('search_query_id', $snapshot->search_query_id)
            ->where('id', '<', $snapshot->id)
            ->latest('id')
            ->first(['tender_ids']);

        if ($previous === null) {
            return $snapshot->tender_ids;
        }

        return array_values(array_diff($snapshot->tender_ids, $previous->tender_ids));
    }
}
