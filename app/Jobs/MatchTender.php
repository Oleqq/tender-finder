<?php

namespace App\Jobs;

use App\Models\Tender;
use App\Services\TenderMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MatchTender implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $tenderId) {}

    public function handle(TenderMatchingService $matching): void
    {
        $tender = Tender::query()->find($this->tenderId);

        if ($tender !== null) {
            $matching->matchTender($tender);
        }
    }
}
