<?php

namespace App\Services;

use App\Models\RostenderApiUsage;
use App\Tenders\RostenderQuotaExceededException;
use Illuminate\Support\Facades\DB;

class RostenderQuotaGuard
{
    public function reserve(bool $mayUseReservedCapacity = false): RostenderQuotaReservation
    {
        $date = now('Europe/Moscow')->toDateString();

        DB::transaction(function () use ($date, $mayUseReservedCapacity): void {
            $usage = $this->lockedUsageForDate($date);

            $limit = max(0, (int) config('tender.rostender.daily_quota_limit', 200));
            $reserve = $mayUseReservedCapacity ? 0 : max(0, (int) config('tender.rostender.daily_quota_reserve', 20));
            $available = max(0, $limit - $reserve);

            if ($usage->successful_requests + $usage->in_flight_requests >= $available) {
                throw new RostenderQuotaExceededException;
            }

            $usage->increment('in_flight_requests');
        });

        return new RostenderQuotaReservation($this, $date);
    }

    public function settle(string $date, bool $successful): void
    {
        DB::transaction(function () use ($date, $successful): void {
            $usage = $this->lockedUsageForDate($date);
            $usage->in_flight_requests = max(0, $usage->in_flight_requests - 1);

            if ($successful) {
                $usage->successful_requests++;
            }

            $usage->save();
        });
    }

    public function observeRemaining(int $remaining): void
    {
        $date = now('Europe/Moscow')->toDateString();
        $spent = max(0, (int) config('tender.rostender.daily_quota_limit', 200) - max(0, $remaining));

        DB::transaction(function () use ($date, $spent): void {
            $usage = $this->lockedUsageForDate($date);

            if ($usage->successful_requests < $spent) {
                $usage->successful_requests = $spent;
                $usage->save();
            }
        });
    }

    private function lockedUsageForDate(string $date): RostenderApiUsage
    {
        // `insertOrIgnore` maps to an atomic upsert/do-nothing operation on
        // supported production databases. It makes the first request after a
        // Moscow midnight safe when multiple workers reserve simultaneously.
        DB::table('rostender_api_usages')->insertOrIgnore([
            'usage_date' => $date,
            'successful_requests' => 0,
            'in_flight_requests' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var RostenderApiUsage $usage */
        $usage = RostenderApiUsage::query()->lockForUpdate()->whereDate('usage_date', $date)->firstOrFail();

        return $usage;
    }
}
