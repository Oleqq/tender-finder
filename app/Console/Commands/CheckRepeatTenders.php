<?php

namespace App\Console\Commands;

use App\Models\RepeatTenderWatch;
use Illuminate\Console\Command;

final class CheckRepeatTenders extends Command
{
    protected $signature = 'tenders:check-repeats';

    protected $description = 'Check personal stored monitoring cards for possible repeat notices';

    public function handle(): int
    {
        RepeatTenderWatch::query()->whereNull('archived_at')->select('user_id')->distinct()->orderBy('user_id')->chunk(100, function ($rows): void {
            foreach ($rows as $row) {
                \App\Jobs\CheckRepeatTenders::dispatch($row->user_id);
            }
        });

        return self::SUCCESS;
    }
}
