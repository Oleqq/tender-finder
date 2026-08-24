<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Services\RssFixtureImportService;
use App\Tenders\EisRssSource;
use App\Tenders\RssSourceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollEisRssFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(EisRssSource $source, RssFixtureImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed === null || $feed->status !== 'active') {
            return;
        }

        try {
            $importer->import($feed, $source->fetch($feed));
        } catch (RssSourceException $exception) {
            $importer->fail($feed, $exception->codeName);
        }
    }
}
