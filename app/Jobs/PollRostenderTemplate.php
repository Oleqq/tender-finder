<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Models\Tender;
use App\Services\RostenderApiClient;
use App\Services\TenderSourceImportService;
use App\Tenders\RostenderApiException;
use App\Tenders\RostenderQuotaExceededException;
use App\Tenders\SourceFetchResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollRostenderTemplate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(RostenderApiClient $api, TenderSourceImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed === null || $feed->source !== 'rostender' || $feed->status !== 'active' || $feed->source_identifier === null) {
            return;
        }

        try {
            $page = $api->template($feed->source_identifier);
            $externalIds = collect($page->items)->map(fn ($item) => (string) $item->id);
            $cached = Tender::query()
                ->where('source', 'rostender')
                ->whereIn('external_id', $externalIds)
                ->get(['id', 'external_id', 'details_fetched_at'])
                ->keyBy('external_id');
            $detailLimit = max(1, (int) config('tender.rostender.max_details_per_poll', 20));
            $details = [];

            foreach ($page->items as $item) {
                $cachedTender = $cached->get((string) $item->id);

                if ($cachedTender?->details_fetched_at !== null) {
                    continue;
                }

                if (count($details) >= $detailLimit) {
                    break;
                }

                $details[] = $api->tender($item->id);
            }

            $queueNotifications = $feed->initialized_at !== null;
            $importer->import($feed, new SourceFetchResult($details, $page->totalCount), 'rostender', false);

            foreach ($details as $detail) {
                $tender = Tender::query()->where('source', 'rostender')->where('external_id', $detail->externalId)->first();

                if ($tender !== null) {
                    MatchRostenderTender::dispatch($tender->id, $feed->id, $queueNotifications);
                }
            }
        } catch (RostenderQuotaExceededException $exception) {
            $importer->fail($feed, $exception->codeName, 'rostender');
        } catch (RostenderApiException $exception) {
            $importer->fail($feed, $exception->codeName, 'rostender');
        }
    }
}
