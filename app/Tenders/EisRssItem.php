<?php

namespace App\Tenders;

use Carbon\CarbonImmutable;

final readonly class EisRssItem
{
    public function __construct(
        public string $externalId,
        public ?string $regNumber,
        public string $canonicalUrl,
        public string $urlHash,
        public string $title,
        public ?string $summary,
        public ?CarbonImmutable $publishedAt,
        public string $contentHash,
    ) {}
}
