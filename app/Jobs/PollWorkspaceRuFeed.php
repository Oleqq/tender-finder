<?php

namespace App\Jobs;

use App\Models\SourceFeed;
use App\Services\TenderSourceImportService;
use App\Services\WorkspaceRuSource;
use App\Tenders\WorkspaceRuException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PollWorkspaceRuFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $feedId) {}

    public function handle(WorkspaceRuSource $source, TenderSourceImportService $importer): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed === null || $feed->source !== 'workspace_ru' || $feed->status !== 'active') {
            return;
        }

        try {
            $importer->import($feed, $source->fetch($feed), 'workspace_ru');
        } catch (WorkspaceRuException $exception) {
            $importer->fail($feed, $exception->codeName, 'workspace_ru');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $feed = SourceFeed::query()->find($this->feedId);

        if ($feed !== null && $feed->source === 'workspace_ru' && $feed->status === 'active') {
            app(TenderSourceImportService::class)->fail($feed, 'poll_job_failed', 'workspace_ru');
        }
    }
}
