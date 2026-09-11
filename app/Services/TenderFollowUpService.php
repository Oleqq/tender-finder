<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Tender;
use App\Models\TenderChange;
use App\Models\TenderUserState;

final class TenderFollowUpService
{
    public function __construct(private readonly AccessService $access) {}

    public function eligible(TenderUserState $state): bool
    {
        return ! in_array($state->status->value, ['dismissed', 'archived'], true)
            && $state->user->telegram_id !== null
            && $this->access->hasActiveAccess($state->user)
            && $state->tender->matches()->whereHas('searchQuery', fn ($q) => $q
                ->where('user_id', $state->user_id)->where('status', 'active'))->exists();
    }

    public function queueReminders(): void
    {
        TenderUserState::query()->with(['user', 'tender'])
            ->where(fn ($q) => $q->where('deadline_reminders_enabled', true)->orWhere('action_reminder_enabled', true))
            ->each(function (TenderUserState $state): void {
                if (! $this->eligible($state)) {
                    return;
                }
                $timezone = NotificationPreference::query()->where('user_id', $state->user_id)->value('timezone') ?? 'Europe/Moscow';
                $localNow = now($timezone);
                $deadline = $state->tender->deadline_at;
                if ($state->deadline_reminders_enabled && $deadline !== null && $deadline->isFuture()) {
                    $hours = now()->diffInHours($deadline, false);
                    $threshold = $hours <= 24 ? 24 : ($hours <= 72 ? 72 : null);
                    if ($threshold !== null) {
                        $this->queue($state, 'tender_deadline', $deadline->timestamp.':'.$threshold, [
                            'deadline' => $deadline->copy()->setTimezone($timezone)->format('d.m.Y H:i T'),
                            'deadline_at' => $deadline->toAtomString(),
                            'threshold' => $threshold,
                        ]);
                    }
                }
                if ($state->action_reminder_enabled && $state->next_action_on?->format('Y-m-d') === $localNow->format('Y-m-d')
                    && $localNow->hour >= 9) {
                    $this->queue($state, 'tender_action', $localNow->format('Y-m-d'), ['action_on' => $localNow->format('Y-m-d')]);
                }
            });
    }

    /** @param array<string, string|null> $before */
    public function recordChanges(Tender $tender, array $before): void
    {
        $changes = [];
        foreach (TenderFacts::snapshot($tender) as $field => $after) {
            // Missing fields in a partial source response are not a cancellation.
            if ($before[$field] !== null && $after !== null && $before[$field] !== $after) {
                $changes[$field] = ['before' => $before[$field], 'after' => $after];
            }
        }
        if ($changes === []) {
            return;
        }
        $change = TenderChange::query()->create(['tender_id' => $tender->id, 'changes' => $changes]);
        TenderUserState::query()->with(['user', 'tender'])->where('tender_id', $tender->id)
            ->where('watch_changes', true)->each(function (TenderUserState $state) use ($change, $changes): void {
                if ($this->eligible($state)) {
                    $this->queue($state, 'tender_change', (string) $change->id, ['changes' => $changes]);
                }
            });
    }

    /** @param array<string, mixed> $payload */
    private function queue(TenderUserState $state, string $type, string $event, array $payload): void
    {
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => $type.':'.$state->user_id.':'.$state->tender_id.':'.$event],
            [
                'user_id' => $state->user_id, 'tender_id' => $state->tender_id,
                'type' => $type, 'status' => NotificationStatus::Queued, 'scheduled_at' => now(),
                'payload' => [...$payload, 'title' => mb_substr($state->tender->title, 0, 500), 'url' => $state->tender->canonical_url],
            ],
        );
        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }
    }
}
