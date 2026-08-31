<?php

namespace App\Services;

final readonly class LocalMvpEisRssSearchResult
{
    /** @param list<array<string, mixed>> $tenders */
    public function __construct(
        public LocalMvpEisRssImportResult $preview,
        public array $tenders,
    ) {}
}
