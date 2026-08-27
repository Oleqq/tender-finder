<?php

namespace App\Tenders;

final readonly class SourceFetchResult
{
    /**
     * @param  list<TenderSourceItem>  $items
     */
    public function __construct(
        public array $items,
        public ?int $itemsReturned = null,
    ) {}
}
