<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Services\PlatformCatalogFeedService;
use App\Services\PlatformCatalogSource;
use App\Services\TenderSourceImportService;
use App\Tenders\PlatformCatalogException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PollPlatformCatalogFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(PlatformCatalogSource $source, TenderSourceImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);
        if ($feed === null || ! isset(PlatformCatalogFeedService::URLS[$feed->source]) || $feed->status !== 'active') {
            return;
        }

        try {
            $importer->import($feed, $source->fetch($feed), $feed->source);
        } catch (PlatformCatalogException $exception) {
            $importer->fail($feed, $exception->codeName, $feed->source);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $feed = SourceFeed::query()->find($this->feedId);
        if ($feed !== null && isset(PlatformCatalogFeedService::URLS[$feed->source]) && $feed->status === 'active') {
            app(TenderSourceImportService::class)->fail($feed, 'poll_job_failed', $feed->source);
        }
    }
}
