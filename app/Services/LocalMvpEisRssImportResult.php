<?php

namespace App\Services;

final readonly class LocalMvpEisRssImportResult
{
    /**
     * @param  list<string>  $externalIds
     */
    public function __construct(
        public int $itemsSeen,
        public int $itemsMatched,
        public int $itemsCreated,
        public int $pagesRequested,
        public int $pagesLoaded,
        public bool $partiallyLoaded,
        public array $externalIds,
    ) {}
}
