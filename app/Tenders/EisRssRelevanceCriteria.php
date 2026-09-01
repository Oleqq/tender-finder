<?php

namespace App\Tenders;

final readonly class EisRssRelevanceCriteria
{
    /** @param list<string> $minusKeywords */
    public function __construct(
        public string $phrase,
        public EisRssMatchMode $matchMode = EisRssMatchMode::All,
        public array $minusKeywords = [],
    ) {}
}
