<?php

namespace App\Services;

use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;
use App\Models\User;

final class LocalMvpSearchSnapshotService
{
    /**
     * @param  list<array<string, mixed>>  $tenders
     * @param  array{match_mode: string, minus_keywords: list<string>}  $relevance
     */
    public function remember(
        User $user,
        string $query,
        LocalMvpEisRssImportResult $result,
        array $tenders,
        array $relevance,
        ?SearchQuery $savedQuery = null,
    ): LocalMvpSearchSnapshot {
        $tenderIds = array_map(
            fn (array $tender): int => (int) $tender['id'],
            $tenders,
        );
        $matchReasons = [];

        foreach ($tenders as $tender) {
            if (is_array($tender['match_reason'] ?? null)) {
                $matchReasons[(string) $tender['id']] = $tender['match_reason'];
            }
        }

        /** @var LocalMvpSearchSnapshot $snapshot */
        $snapshot = LocalMvpSearchSnapshot::query()->create([
            'user_id' => $user->id,
            'search_query_id' => $savedQuery?->id,
            'query' => $query,
            'source' => 'eis_rss',
            'tender_ids' => array_values(array_unique($tenderIds)),
            'relevance' => [
                ...$relevance,
                'match_reasons' => $matchReasons,
            ],
            'items_seen' => $result->itemsSeen,
            'items_matched' => $result->itemsMatched,
            'items_created' => $result->itemsCreated,
            'pages_requested' => $result->pagesRequested,
            'pages_loaded' => $result->pagesLoaded,
            'partially_loaded' => $result->partiallyLoaded,
        ]);

        return $snapshot;
    }

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
}
