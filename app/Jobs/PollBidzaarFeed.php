<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Services\BidzaarSource;
use App\Services\TenderSourceImportService;
use App\Tenders\BidzaarException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PollBidzaarFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(BidzaarSource $source, TenderSourceImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);
        if ($feed === null || $feed->source !== 'bidzaar' || $feed->status !== 'active') {
            return;
        }

        try {
            $importer->import($feed, $source->fetch($feed), 'bidzaar');
        } catch (BidzaarException $exception) {
            $importer->fail($feed, $exception->codeName, 'bidzaar');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $feed = SourceFeed::query()->find($this->feedId);
        if ($feed !== null && $feed->source === 'bidzaar' && $feed->status === 'active') {
            app(TenderSourceImportService::class)->fail($feed, 'poll_job_failed', 'bidzaar');
        }
    }
}
