<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Services\B2bCenterSource;
use App\Services\TenderSourceImportService;
use App\Tenders\B2bCenterException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PollB2bCenterFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(B2bCenterSource $source, TenderSourceImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed === null || $feed->source !== 'b2b_center' || $feed->status !== 'active') {
            return;
        }

        try {
            $importer->import($feed, $source->fetch($feed), 'b2b_center');
        } catch (B2bCenterException $exception) {
            $importer->fail($feed, $exception->codeName, 'b2b_center');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed !== null && $feed->source === 'b2b_center' && $feed->status === 'active') {
            app(TenderSourceImportService::class)->fail($feed, 'poll_job_failed', 'b2b_center');
        }
    }
}
