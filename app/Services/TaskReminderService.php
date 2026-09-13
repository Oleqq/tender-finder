<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\Team;
use App\Models\TenderChecklistItem;
use App\Models\User;

final class TaskReminderService
{
    public function recipient(TenderChecklistItem $item): ?User
    {
        $p = $item->participation;
        if (! $item->reminder_enabled || $item->completed_at || ! $item->due_on || in_array($p->stage->value, ['won', 'lost'], true)) {
            return null;
        }
        $user = User::query()->find($p->team_id ? $item->assignee_id : $p->user_id);
        if (! $user || ! $user->telegram_id || ! app(AccessService::class)->hasActiveAccess($user)) {
            return null;
        }
        if ($p->team_id) {
            $team = Team::query()->find($p->team_id);
            if (! $team || ! in_array(app(TeamWorkspaceService::class)->role($user, $team), ['owner', 'member'], true)) {
                return null;
            }
        } else {
            if (! app(TenderWorkService::class)->accessible($user)->whereKey($p->tender_id)->exists()) {
                return null;
            }
            if ($p->tender->userStates()->where('user_id', $user->id)->whereIn('status', ['dismissed', 'archived'])->exists()) {
                return null;
            }
        }

        return $user;
    }

    public function phase(TenderChecklistItem $item, User $user): ?string
    {
        $localNow = now(app(TenderCalendarService::class)->timezone($user));
        if ($localNow->hour < 9 || ! $item->due_on) {
            return null;
        }
        $due = $item->due_on->format('Y-m-d');
        if ($due === $localNow->copy()->addDay()->format('Y-m-d')) {
            return 'upcoming';
        }
        if ($due < $localNow->format('Y-m-d')) {
            return 'overdue';
        }

        return null;
    }

    public function queueDue(): void
    {
        TenderChecklistItem::query()->where('reminder_enabled', true)->whereNull('completed_at')->whereNotNull('due_on')
            ->with('participation.tender')->lazyById()->each(function (TenderChecklistItem $item): void {
                $user = $this->recipient($item);
                if (! $user || ! ($phase = $this->phase($item, $user))) {
                    return;
                }
                $due = $item->due_on->format('Y-m-d');
                $p = $item->participation;
                $delivery = NotificationDelivery::query()->firstOrCreate(
                    ['idempotency_key' => 'task:'.$item->id.':'.$user->id.':'.$due.':'.$phase],
                    ['user_id' => $user->id, 'tender_id' => $p->tender_id, 'type' => 'task_reminder', 'status' => NotificationStatus::Queued,
                        'scheduled_at' => now(), 'payload' => ['item_id' => $item->id, 'due_on' => $due, 'phase' => $phase,
                            'title' => $item->title, 'tender_title' => mb_substr($p->tender->title, 0, 500),
                            'url' => route('tenders.work', ['tender' => $p->tender_id, ...($p->team_id ? ['team_id' => $p->team_id] : [])])]],
                );
                if ($delivery->wasRecentlyCreated) {
                    DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
                }
            });
    }

    public function stillDue(NotificationDelivery $delivery): bool
    {
        $payload = $delivery->payload ?? [];
        $item = TenderChecklistItem::query()->with('participation.tender')->find($payload['item_id'] ?? 0);
        if (! $item || ! ($user = $this->recipient($item))) {
            return false;
        }

        return $user->id === $delivery->user_id && $item->due_on?->format('Y-m-d') === ($payload['due_on'] ?? null)
            && $this->phase($item, $user) === ($payload['phase'] ?? null);
    }
}
