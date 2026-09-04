<?php

namespace App\Services;

use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\User;
use App\Tenders\EisRssRelevanceCriteria;
use App\Tenders\EisRssSearchCriteria;
use App\Tenders\EisRssSearchUrlFactory;

final class LocalMvpEisRssSearchService
{
    public function __construct(
        private readonly LocalMvpEisRssImportService $importer,
        private readonly LocalMvpSearchSnapshotService $snapshots,
        private readonly LocalMvpTenderWorkspaceService $workspace,
        private readonly EisRssSearchUrlFactory $searchUrls,
        private readonly TenderMatchingService $matching,
    ) {}

    public function run(
        User $user,
        EisRssRelevanceCriteria $relevance,
        ?string $rssUrl,
        int $pages,
        EisRssSearchCriteria $criteria,
        ?SearchQuery $savedQuery = null,
    ): LocalMvpEisRssSearchResult {
        $url = $rssUrl !== null && trim($rssUrl) !== ''
            ? trim($rssUrl)
            : $this->searchUrls->forPhrase($relevance->phrase, $criteria);

        $preview = $this->importer->import($url, $relevance, $pages);

        if ($savedQuery !== null && $preview->externalIds !== []) {
            Tender::query()
                ->where('source', 'eis_rss')
                ->whereIn('external_id', $preview->externalIds)
                ->each(fn (Tender $tender) => $this->matching->matchTender($tender, false));
        }

        $tenders = $this->workspace->tendersForSourceExternalIds(
            $user,
            'eis_rss',
            $preview->externalIds,
            $preview->matchReasonsByExternalId,
        );

        $this->snapshots->remember(
            $user,
            $relevance->phrase,
            $preview,
            $tenders,
            [
                'match_mode' => $relevance->matchMode->value,
                'minus_keywords' => $relevance->minusKeywords,
            ],
            $savedQuery,
        );

        return new LocalMvpEisRssSearchResult($preview, $tenders);
    }
}
