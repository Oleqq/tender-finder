<?php

namespace App\Services;

final readonly class LocalMvpEisRssImportResult
{
    /**
     * @param  list<string>  $externalIds
     * @param  array<string, array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}>  $matchReasonsByExternalId
     */
    public function __construct(
        public int $itemsSeen,
        public int $itemsMatched,
        public int $itemsCreated,
        public int $pagesRequested,
        public int $pagesLoaded,
        public bool $partiallyLoaded,
        public array $externalIds,
        public array $matchReasonsByExternalId,
    ) {}
}
