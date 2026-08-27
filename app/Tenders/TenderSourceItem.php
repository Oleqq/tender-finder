<?php

namespace App\Tenders;

use Carbon\CarbonImmutable;

abstract readonly class TenderSourceItem
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $externalId,
        public ?string $regNumber,
        public string $canonicalUrl,
        public string $urlHash,
        public string $title,
        public ?string $summary,
        public ?CarbonImmutable $publishedAt,
        public string $contentHash,
        public ?string $region = null,
        public ?string $budgetAmount = null,
        public string $currency = 'RUB',
        public ?CarbonImmutable $deadlineAt = null,
        public array $metadata = [],
    ) {}
}
