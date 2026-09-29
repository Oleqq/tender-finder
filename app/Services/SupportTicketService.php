<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupportTicketService
{
    public function create(User $user, string $category, string $body): SupportTicket
    {
        return DB::transaction(function () use ($user, $category, $body): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'user_id' => $user->id,
                'category' => $category,
                'status' => 'open',
            ]);
            $ticket->messages()->create([
                'author_id' => $user->id,
                'is_staff' => false,
                'body' => $body,
                'created_at' => now(),
            ]);
            $ticket->events()->create([
                'actor_id' => $user->id,
                'action' => 'created',
                'new_status' => 'open',
                'created_at' => now(),
            ]);

            return $ticket;
        });
    }

    public function reply(SupportTicket $ticket, User $actor, string $body, bool $isStaff): void
    {
        DB::transaction(function () use ($ticket, $actor, $body, $isStaff): void {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if (! $isStaff && $locked->user_id !== $actor->id) {
                abort(403);
            }

            $oldStatus = $locked->status;
            $newStatus = match (true) {
                ! $isStaff && $oldStatus === 'resolved' => 'open',
                $isStaff && $oldStatus === 'open' => 'in_progress',
                default => $oldStatus,
            };
            $locked->messages()->create([
                'author_id' => $actor->id,
                'is_staff' => $isStaff,
                'body' => $body,
                'created_at' => now(),
            ]);
            if ($newStatus !== $oldStatus) {
                $locked->status = $newStatus;
            }
            $locked->touch();
            $locked->events()->create([
                'actor_id' => $actor->id,
                'action' => $isStaff ? 'staff_reply' : 'user_reply',
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'created_at' => now(),
            ]);
        });
    }

    public function updateWorkflow(SupportTicket $ticket, User $actor, string $status, ?int $assigneeId, string $reason): void
    {
        DB::transaction(function () use ($ticket, $actor, $status, $assigneeId, $reason): void {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($assigneeId !== null) {
                $assignee = User::query()->find($assigneeId);
                if ($assignee?->role !== UserRole::SuperAdmin) {
                    throw ValidationException::withMessages(['assignee_id' => 'Ответственный должен быть администратором.']);
                }
            }
            if ($locked->status === $status && $locked->assignee_id === $assigneeId) {
                throw ValidationException::withMessages(['status' => 'Изменений нет.']);
            }

            $locked->events()->create([
                'actor_id' => $actor->id,
                'action' => 'workflow_changed',
                'old_status' => $locked->status,
                'new_status' => $status,
                'old_assignee_id' => $locked->assignee_id,
                'new_assignee_id' => $assigneeId,
                'reason' => $reason,
                'created_at' => now(),
            ]);
            $locked->forceFill(['status' => $status, 'assignee_id' => $assigneeId])->save();
        });
    }
}
