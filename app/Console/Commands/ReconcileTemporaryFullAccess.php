<?php

namespace App\Console\Commands;

use App\Enums\QueryStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AccessFreezeService;
use App\Services\TemporaryFullAccessService;
use Illuminate\Console\Command;

final class ReconcileTemporaryFullAccess extends Command
{
    protected $signature = 'access:reconcile-temporary-full-access';

    protected $description = 'Freeze monitorings that lost temporary review access after its deadline.';

    public function handle(TemporaryFullAccessService $temporary, AccessFreezeService $freeze): int
    {
        $until = $temporary->until();
        if ($until === null || $temporary->isActive()) {
            return self::SUCCESS;
        }

        User::query()
            ->where('role', UserRole::Subscriber)
            ->whereHas('searchQueries', fn ($query) => $query->where('status', QueryStatus::Active))
            ->chunkById(100, function ($users) use ($freeze): void {
                foreach ($users as $user) {
                    $freeze->reconcileCurrentLimit($user, now());
                }
            });

        return self::SUCCESS;
    }
}
