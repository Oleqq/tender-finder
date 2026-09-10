<?php

namespace App\Jobs;

use App\Enums\QueryStatus;
use App\Models\RostenderFeedSearchQuery;
use App\Models\Tender;
use App\Services\TenderMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MatchRostenderTender implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenderId,
        public readonly int $feedId,
        public readonly bool $queueNotifications,
    ) {}

    public function handle(TenderMatchingService $matching): void
    {
        $tender = Tender::query()->find($this->tenderId);

        if ($tender === null || $tender->source !== 'rostender') {
            return;
        }

        $queries = RostenderFeedSearchQuery::query()
            ->where('source_feed_id', $this->feedId)
            ->whereHas('searchQuery', fn ($builder) => $builder->where('status', QueryStatus::Active))
            ->with('searchQuery')
            ->get()
            ->map(fn (RostenderFeedSearchQuery $link) => $link->searchQuery);

        $matching->matchTenderForQueries($tender, $queries, $this->queueNotifications);
    }
}
