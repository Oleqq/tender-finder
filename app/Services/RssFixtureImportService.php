<?php

namespace App\Services;

use App\Jobs\MatchTender;
use App\Models\SourceFeed;
use App\Models\SourceFeedItem;
use App\Models\SourceRun;
use App\Models\Tender;
use App\Tenders\SourceFetchResult;
use Illuminate\Support\Facades\DB;

class RssFixtureImportService
{
    public function import(SourceFeed $feed, SourceFetchResult $result): SourceRun
    {
        return DB::transaction(function () use ($feed, $result): SourceRun {
            $feed = $feed->fresh();
            $isFirstSuccessfulPoll = $feed->initialized_at === null;
            $run = SourceRun::query()->create([
                'source_feed_id' => $feed->id,
                'source' => 'eis_rss',
                'status' => 'running',
                'started_at' => now(),
                'items_seen' => count($result->items),
            ]);
            $itemsCreated = 0;

            foreach ($result->items as $item) {
                $sourceItem = SourceFeedItem::query()->firstOrCreate(
                    ['source_feed_id' => $feed->id, 'url_hash' => $item->urlHash],
                    [
                        'external_id' => $item->externalId,
                        'canonical_url' => $item->canonicalUrl,
                        'title' => $item->title,
                        'summary' => $item->summary,
                        'published_at' => $item->publishedAt,
                        'content_hash' => $item->contentHash,
                        'discovered_at' => now(),
                    ],
                );

                if (! $sourceItem->wasRecentlyCreated) {
                    continue;
                }

                $itemsCreated++;
                $tender = Tender::query()->firstOrCreate(
                    ['source' => 'eis_rss', 'external_id' => $item->externalId],
                    [
                        'source_feed_item_id' => $sourceItem->id,
                        'reg_number' => $item->regNumber,
                        'canonical_url' => $item->canonicalUrl,
                        'canonical_url_hash' => $item->urlHash,
                        'title' => $item->title,
                        'description' => $item->summary,
                        'published_at' => $item->publishedAt,
                    ],
                );

                if (! $isFirstSuccessfulPoll && $tender->wasRecentlyCreated) {
                    MatchTender::dispatch($tender->id)->afterCommit();
                }
            }

            $feed->forceFill([
                'initialized_at' => $feed->initialized_at ?? now(),
                'last_attempt_at' => now(),
                'last_success_at' => now(),
                'last_error_code' => null,
                'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
            ])->save();

            $run->forceFill([
                'status' => 'succeeded',
                'finished_at' => now(),
                'items_created' => $itemsCreated,
            ])->save();

            return $run;
        });
    }

    public function fail(SourceFeed $feed, string $errorCode): SourceRun
    {
        $feed->forceFill([
            'last_attempt_at' => now(),
            'last_error_code' => $errorCode,
            'next_poll_at' => now()->addSeconds($feed->poll_interval_seconds),
        ])->save();

        /** @var SourceRun $run */
        $run = SourceRun::query()->create([
            'source_feed_id' => $feed->id,
            'source' => 'eis_rss',
            'status' => 'failed',
            'started_at' => now(),
            'finished_at' => now(),
            'error_code' => $errorCode,
        ]);

        return $run;
    }
}
