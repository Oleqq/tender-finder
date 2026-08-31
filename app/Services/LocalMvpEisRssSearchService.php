<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\User;
use App\Tenders\EisRssSearchCriteria;
use App\Tenders\EisRssSearchUrlFactory;

final class LocalMvpEisRssSearchService
{
    public function __construct(
        private readonly LocalMvpEisRssImportService $importer,
        private readonly LocalMvpSearchSnapshotService $snapshots,
        private readonly LocalMvpTenderWorkspaceService $workspace,
        private readonly EisRssSearchUrlFactory $searchUrls,
    ) {}

    public function run(
        User $user,
        string $phrase,
        ?string $rssUrl,
        int $pages,
        EisRssSearchCriteria $criteria,
        ?SearchQuery $savedQuery = null,
    ): LocalMvpEisRssSearchResult {
        $url = $rssUrl !== null && trim($rssUrl) !== ''
            ? trim($rssUrl)
            : $this->searchUrls->forPhrase($phrase, $criteria);

        $preview = $this->importer->import($url, $phrase, $pages);
        $tenders = $this->workspace->tendersForSourceExternalIds(
            $user,
            'eis_rss',
            $preview->externalIds,
        );

        $this->snapshots->remember(
            $user,
            $phrase,
            $preview,
            array_map(fn (array $tender): int => $tender['id'], $tenders),
            $savedQuery,
        );

        return new LocalMvpEisRssSearchResult($preview, $tenders);
    }
}
