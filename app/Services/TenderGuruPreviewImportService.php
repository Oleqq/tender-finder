<?php

namespace App\Services;

use App\Models\SourceFeed;
use App\Models\Tender;
use App\Tenders\TenderGuruPreviewSource;

final class TenderGuruPreviewImportService
{
    public function __construct(
        private readonly TenderGuruPreviewSource $source,
        private readonly TenderSourceImportService $importer,
        private readonly TenderMatchingService $matching,
    ) {}

    public function import(string $query): TenderGuruPreviewImportResult
    {
        $result = $this->source->fetch($query);
        $queryHash = hash('sha256', $query);
        $feed = SourceFeed::query()->firstOrCreate(
            ['url_hash' => hash('sha256', "tenderguru-preview:{$queryHash}")],
            [
                'canonical_url' => "tenderguru-preview://{$queryHash}",
                'status' => 'manual_preview',
                'poll_interval_seconds' => 0,
            ],
        );
        $run = $this->importer->import($feed, $result, 'tenderguru_preview', false);
        $externalIds = array_values(array_unique(array_map(
            fn ($item): string => $item->externalId,
            $result->items,
        )));
        $matchesCreated = 0;

        Tender::query()
            ->where('source', 'tenderguru_preview')
            ->whereIn('external_id', $externalIds)
            ->each(function (Tender $tender) use (&$matchesCreated): void {
                $matchesCreated += $this->matching->matchTender($tender, false);
            });

        return new TenderGuruPreviewImportResult(
            itemsSeen: $run->items_seen,
            itemsMatched: count($result->items),
            itemsCreated: $run->items_created,
            matchesCreated: $matchesCreated,
            externalIds: $externalIds,
        );
    }
}
