<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\SupportTicketMessage;

final class SupportTicketPresenter
{
    /** @return array<string, mixed> */
    public function summary(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'category' => $ticket->category,
            'status' => $ticket->status,
            'assignee_id' => $ticket->assignee_id,
            'created_at' => $ticket->created_at?->toAtomString(),
            'updated_at' => $ticket->updated_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(SupportTicket $ticket, bool $isStaff): array
    {
        $result = $this->summary($ticket);
        $result['messages'] = $ticket->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (SupportTicketMessage $message): array => [
                'id' => $message->id,
                'is_staff' => $message->is_staff,
                'body' => $message->body,
                'created_at' => $message->created_at->toAtomString(),
            ])->all();

        if ($isStaff) {
            $result['events'] = $ticket->events()
                ->orderBy('id')
                ->get()
                ->map(fn (SupportTicketEvent $event): array => [
                    'id' => $event->id,
                    'actor_id' => $event->actor_id,
                    'action' => $event->action,
                    'old_status' => $event->old_status,
                    'new_status' => $event->new_status,
                    'old_assignee_id' => $event->old_assignee_id,
                    'new_assignee_id' => $event->new_assignee_id,
                    'reason' => $event->reason,
                    'access_entitlement_id' => $event->access_entitlement_id,
                    'access_plan_code' => $event->access_plan_code,
                    'access_ends_at' => $event->access_ends_at?->toAtomString(),
                    'created_at' => $event->created_at->toAtomString(),
                ])->all();
        }

        return $result;
    }
}
