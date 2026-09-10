<?php

namespace App\Services;

use App\Jobs\PollRostenderTemplate;
use App\Models\SourceFeed;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RostenderManualCheckService
{
    public function __construct(private readonly RostenderAccessGate $gate) {}

    public function queue(User $user, SourceFeed $feed): void
    {
        $this->gate->assertDataProcessingAllowed();

        if ($feed->source !== 'rostender') {
            throw new RuntimeException('invalid_rostender_feed');
        }

        $limit = $this->manualCheckLimitFor($user);
        $key = 'rostender-manual-checks:'.$user->id.':'.now('Europe/Moscow')->toDateString();
        $used = (int) Cache::get($key, 0);

        if ($used >= $limit) {
            throw new RuntimeException('rostender_manual_check_limit_reached');
        }

        Cache::put($key, $used + 1, now('Europe/Moscow')->endOfDay());
        PollRostenderTemplate::dispatch($feed->id);
    }

    private function manualCheckLimitFor(User $user): int
    {
        $plan = app(AccessService::class)->snapshotFor($user)->planCode;

        return match ($plan) {
            PlanCatalog::BASIC_CODE => max(0, (int) config('tender.rostender.basic_manual_checks_per_day', 0)),
            PlanCatalog::PRO_CODE => max(0, (int) config('tender.rostender.pro_manual_checks_per_day', 0)),
            default => 0,
        };
    }
}
