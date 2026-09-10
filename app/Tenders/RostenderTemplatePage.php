<?php

namespace App\Tenders;

final readonly class RostenderTemplatePage
{
    /** @param list<RostenderTemplateListItem> $items */
    public function __construct(
        public array $items,
        public int $totalCount,
        public int $pageCount,
    ) {}
}
