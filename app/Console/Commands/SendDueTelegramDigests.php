<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\TenderQueryMatch;
use App\Services\AccessService;
use Illuminate\Console\Command;

class SendDueTelegramDigests extends Command
{
    protected $signature = 'notifications:send-due-digests';

    protected $description = 'Queue one daily Telegram digest for each user at their selected local time';

    public function handle(AccessService $access): int
    {
        NotificationPreference::query()
            ->with('user')
            ->where('digest_enabled', true)
            ->each(function (NotificationPreference $preference) use ($access): void {
                $user = $preference->user;

                if (! $access->hasActiveAccess($user)) {
                    return;
                }

                $localNow = now()->setTimezone($preference->timezone);

                if ($localNow->format('H:i') !== substr((string) $preference->digest_time, 0, 5)) {
                    return;
                }

                $dayStart = $localNow->copy()->startOfDay()->utc();
                $dayEnd = $localNow->copy()->endOfDay()->utc();
                $matches = TenderQueryMatch::query()
                    ->with('tender')
                    ->whereHas('searchQuery', fn ($query) => $query->where('user_id', $user->id))
                    ->whereBetween('matched_at', [$dayStart, $dayEnd])
                    ->latest('matched_at')
                    ->get();

                if ($matches->isEmpty()) {
                    return;
                }

                $delivery = NotificationDelivery::query()->firstOrCreate(
                    ['idempotency_key' => 'daily-digest:'.$user->id.':'.$localNow->format('Ymd')],
                    [
                        'user_id' => $user->id,
                        'type' => 'tender_digest',
                        'status' => NotificationStatus::Queued,
                        'payload' => [
                            'count' => $matches->count(),
                            'tenders' => $matches->take(10)->map(fn (TenderQueryMatch $match): array => [
                                'title' => mb_substr($match->tender->title, 0, 240),
                                'url' => $match->tender->canonical_url,
                            ])->all(),
                        ],
                        'scheduled_at' => now(),
                    ],
                );

                if ($delivery->wasRecentlyCreated) {
                    DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
                }
            });

        return self::SUCCESS;
    }
}
