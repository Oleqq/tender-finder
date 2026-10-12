<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Services\EtpRegionSource;
use App\Services\TenderSourceImportService;
use App\Tenders\EtpRegionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PollEtpRegionFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(EtpRegionSource $source, TenderSourceImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);
        if ($feed === null || $feed->source !== 'etp_region' || $feed->status !== 'active') {
            return;
        }

        try {
            $importer->import($feed, $source->fetch($feed), 'etp_region');
        } catch (EtpRegionException $exception) {
            $importer->fail($feed, $exception->codeName, 'etp_region');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $feed = SourceFeed::query()->find($this->feedId);
        if ($feed !== null && $feed->source === 'etp_region' && $feed->status === 'active') {
            app(TenderSourceImportService::class)->fail($feed, 'poll_job_failed', 'etp_region');
        }
    }
}
