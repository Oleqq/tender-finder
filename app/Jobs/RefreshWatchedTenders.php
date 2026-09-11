<?php

namespace App\Jobs;

use App\Models\Tender;
use App\Services\RostenderAccessGate;
use App\Services\RostenderApiClient;
use App\Services\TenderFollowUpService;
use App\Services\TenderSourceImportService;
use App\Tenders\RostenderApiException;
use App\Tenders\RostenderQuotaExceededException;
use App\Tenders\SourceFetchResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RefreshWatchedTenders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 3600;

    public int $tries = 1;

    public function handle(RostenderAccessGate $gate, RostenderApiClient $api, TenderSourceImportService $importer, TenderFollowUpService $followUp): void
    {
        if (! $gate->allowsDataProcessing()) {
            return;
        }
        $tenders = Tender::query()->where('source', 'rostender')
            ->whereHas('userStates', fn ($q) => $q->where('watch_changes', true)->whereNotIn('status', ['dismissed', 'archived']))
            ->where(fn ($q) => $q->whereNull('watch_checked_at')->orWhere('watch_checked_at', '<=', now()->subHours(6)))
            ->with(['sourceFeedItem', 'userStates.user'])
            ->orderByRaw('watch_checked_at IS NOT NULL')->orderBy('watch_checked_at')->orderBy('id')
            ->limit(max(1, (int) config('tender.rostender.watch_refresh_limit', 5)))->get();

        foreach ($tenders as $tender) {
            $eligible = $tender->userStates->contains(function ($state) use ($tender, $followUp): bool {
                $state->setRelation('tender', $tender);

                return $state->watch_changes && $followUp->eligible($state);
            });
            $feed = $tender->sourceFeedItem?->feed;
            if (! $eligible || $feed === null) {
                $tender->update(['watch_checked_at' => now()]);

                continue;
            }
            try {
                $detail = $api->tender((int) $tender->external_id);
                $importer->import($feed, new SourceFetchResult([$detail]), 'rostender', false, false);
                $tender->update(['watch_checked_at' => now()]);
            } catch (RostenderQuotaExceededException) {
                break;
            } catch (RostenderApiException) {
                // Rotate failed cards so one unavailable tender cannot starve the rest.
                $tender->update(['watch_checked_at' => now()]);
            }
        }
    }
}
