<?php

namespace App\Services;

final readonly class TenderGuruPreviewImportResult
{
    public function __construct(
        public int $itemsSeen,
        public int $itemsMatched,
        public int $itemsCreated,
        public int $matchesCreated,
        /** @var list<string> */
        public array $externalIds,
    ) {}
}
