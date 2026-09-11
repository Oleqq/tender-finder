<?php

namespace App\Services;

use App\Jobs\MatchTender;
use App\Models\SourceFeed;
use App\Models\SourceFeedItem;
use App\Models\SourceRun;
use App\Models\Tender;
use App\Tenders\SourceFetchResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TenderSourceImportService
{
    public function import(SourceFeed $feed, SourceFetchResult $result, string $source, bool $queueMatches = true, bool $updateFeed = true): SourceRun
    {
        return DB::transaction(function () use ($feed, $result, $source, $queueMatches, $updateFeed): SourceRun {
            $feed = $feed->fresh();
            $isFirstSuccessfulPoll = $feed->initialized_at === null;
            $run = SourceRun::query()->create([
                'source_feed_id' => $feed->id,
                'source' => $source,
                'status' => 'running',
                'started_at' => now(),
                'items_seen' => $result->itemsReturned ?? count($result->items),
            ]);
            $itemsCreated = 0;

            foreach ($result->items as $item) {
                $sourceItem = SourceFeedItem::query()->firstOrNew([
                    'source_feed_id' => $feed->id,
                    'url_hash' => $item->urlHash,
                ]);
                $sourceItem->fill([
                    'external_id' => $item->externalId,
                    'canonical_url' => $item->canonicalUrl,
                    'title' => $item->title,
                    'summary' => $item->summary,
                    'published_at' => $item->publishedAt,
                    'content_hash' => $item->contentHash,
                ]);

                if (! $sourceItem->exists) {
                    $sourceItem->discovered_at = now();
                }

                $sourceItem->save();

                $tender = Tender::query()->firstOrNew([
                    'source' => $source,
                    'external_id' => $item->externalId,
                ]);
                $isNewTender = ! $tender->exists;
                if (! $isNewTender) {
                    $tender = Tender::query()->lockForUpdate()->findOrFail($tender->id);
                }
                $before = TenderFacts::snapshot($tender);
                /** @var mixed $rawStoredMetadata */
                $rawStoredMetadata = $tender->getAttribute('metadata');
                $storedMetadata = is_array($rawStoredMetadata) ? $rawStoredMetadata : [];
                $metadata = array_replace($storedMetadata, array_filter($item->metadata, fn ($value) => $value !== null));
                if (is_array($item->metadata['rostender'] ?? null)) {
                    $metadata['rostender'] = array_replace($storedMetadata['rostender'] ?? [], array_filter($item->metadata['rostender'], fn ($value) => $value !== null));
                }
                $tender->fill([
                    'source_feed_item_id' => $sourceItem->id,
                    'reg_number' => $item->regNumber,
                    'canonical_url' => $item->canonicalUrl,
                    'canonical_url_hash' => $item->urlHash,
                    'title' => $item->title,
                    'description' => $item->summary,
                    'region' => $item->region,
                    'budget_amount' => $item->budgetAmount ?? $tender->budget_amount,
                    'currency' => $item->currency,
                    'published_at' => $item->publishedAt,
                    'deadline_at' => $item->deadlineAt ?? $tender->deadline_at,
                    'metadata' => $metadata === [] ? null : $metadata,
                ]);

                if ($item->externalUpdatedAt !== null) {
                    $tender->external_updated_at = Carbon::instance($item->externalUpdatedAt);
                }

                if ($item->detailsFetchedAt !== null) {
                    $tender->details_fetched_at = Carbon::instance($item->detailsFetchedAt);
                }
                $tender->save();
                if (! $isNewTender) {
                    app(TenderFollowUpService::class)->recordChanges($tender, $before);
                }

                if ($isNewTender) {
                    $itemsCreated++;
                }

                if ($queueMatches && $isNewTender) {
                    // The first import builds the user's initial feed but must not
                    // send a burst of notifications for historic procurements.
                    MatchTender::dispatch(
                        $tender->id,
                        ! $isFirstSuccessfulPoll,
                    )->afterCommit();
                }
            }

            if ($updateFeed) {
                $feed->forceFill([
                    'initialized_at' => $feed->initialized_at ?? now(),
                    'last_attempt_at' => now(),
                    'last_success_at' => now(),
                    'last_error_code' => null,
                    'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
                ])->save();

            }

            $run->forceFill([
                'status' => 'succeeded',
                'finished_at' => now(),
                'items_created' => $itemsCreated,
            ])->save();

            return $run;
        });
    }

    public function fail(SourceFeed $feed, string $errorCode, string $source): SourceRun
    {
        $feed->forceFill([
            'last_attempt_at' => now(),
            'last_error_code' => $errorCode,
            'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
        ])->save();

        /** @var SourceRun $run */
        $run = SourceRun::query()->create([
            'source_feed_id' => $feed->id,
            'source' => $source,
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'error_code' => $errorCode,
        ]);

        return $run;
    }
}
