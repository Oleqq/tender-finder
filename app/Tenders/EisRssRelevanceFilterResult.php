<?php

namespace App\Tenders;

final readonly class EisRssRelevanceFilterResult
{
    /**
     * @param  list<TenderSourceItem>  $items
     * @param  array<string, array{mode: string, matched_terms: list<string>, minus_keywords_checked: list<string>}>  $reasonsByExternalId
     */
    public function __construct(
        public array $items,
        public array $reasonsByExternalId,
    ) {}
}
