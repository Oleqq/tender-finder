<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Services\SberAstSource;
use App\Services\TenderSourceImportService;
use App\Tenders\SberAstException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PollSberAstFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(SberAstSource $source, TenderSourceImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed === null || $feed->source !== 'sber_ast' || $feed->status !== 'active') {
            return;
        }

        try {
            $importer->import($feed, $source->fetch($feed), 'sber_ast');
        } catch (SberAstException $exception) {
            $importer->fail($feed, $exception->codeName, 'sber_ast');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed !== null && $feed->source === 'sber_ast' && $feed->status === 'active') {
            app(TenderSourceImportService::class)->fail($feed, 'poll_job_failed', 'sber_ast');
        }
    }
}
