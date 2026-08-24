<?php

namespace App\Tenders;

final readonly class SourceFetchResult
{
    /** @param list<EisRssItem> $items */
    public function __construct(public array $items) {}
}
