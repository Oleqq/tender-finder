<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Models\NotificationDelivery;
use App\Models\User;

final class NotificationDeliveryPresenter
{
    /** @return list<array{type: string, status: string, message: string, scheduled_at: string|null, completed_at: string|null}> */
    public function recentFor(User $user): array
    {
        return NotificationDelivery::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (NotificationDelivery $delivery): array => $this->toArray($delivery))
            ->values()
            ->all();
    }

    /** @return array{type: string, status: string, message: string, scheduled_at: string|null, completed_at: string|null} */
    private function toArray(NotificationDelivery $delivery): array
    {
        [$status, $message] = match ($delivery->status) {
            NotificationStatus::Sent => ['sent', 'Отправлено в Telegram.'],
            NotificationStatus::Queued => ['queued', 'Ожидает обработки очередью.'],
            NotificationStatus::Skipped => ['skipped', 'Не отправлено: уведомление больше не актуально.'],
            NotificationStatus::Failed => ['failed', $this->failedMessage($delivery->failure_code)],
        };

        return [
            'type' => $this->typeLabel($delivery->type),
            'status' => $status,
            'message' => $message,
            'scheduled_at' => $delivery->scheduled_at?->toAtomString(),
            'completed_at' => ($delivery->sent_at ?? $delivery->failed_at)?->toAtomString(),
        ];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'tender_card' => 'Новое совпадение',
            'tender_digest' => 'Ежедневный дайджест',
            'tender_deadline' => 'Напоминание о сроке',
            'tender_action' => 'Напоминание о действии',
            'tender_change' => 'Изменение закупки',
            'task_reminder' => 'Напоминание о задаче',
            'team_mention' => 'Упоминание в команде',
            'team_review_assignment' => 'Назначение разбора',
            'team_review_sla' => 'Срок разбора',
            'team_review_digest' => 'Командная сводка',
            'participation_approval' => 'Согласование участия',
            default => 'Сервисное уведомление',
        };
    }

    private function failedMessage(?string $failureCode): string
    {
        return match ($failureCode) {
            'telegram_chat_blocked', 'telegram_chat_unavailable' => 'Откройте личный чат с ботом Tender Finder и нажмите Start — после этого новые уведомления снова смогут прийти.',
            'telegram_bot_not_configured', 'telegram_bot_auth_failed' => 'Сервис уведомлений временно недоступен. Мониторинги продолжают работать.',
            default => 'Не удалось доставить уведомление. Повторная попытка будет выполнена автоматически.',
        };
    }
}
