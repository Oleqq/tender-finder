<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\TenderUserState;
use App\Services\AccessService;
use App\Services\TelegramBotClient;
use App\Services\TenderFollowUpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverTelegramNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(TelegramBotClient $bot, AccessService $access): void
    {
        $delivery = NotificationDelivery::query()->with('user')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== NotificationStatus::Queued || $delivery->user->telegram_id === null) {
            return;
        }

        if (! $access->hasActiveAccess($delivery->user)) {
            $delivery->forceFill([
                'status' => NotificationStatus::Skipped,
                'failure_code' => 'access_expired',
            ])->save();

            return;
        }

        if (in_array($delivery->type, ['tender_deadline', 'tender_action', 'tender_change'], true) && ! $this->followUpStillDue($delivery)) {
            $delivery->forceFill(['status' => NotificationStatus::Skipped, 'failure_code' => 'follow_up_no_longer_due'])->save();

            return;
        }

        try {
            $payload = $delivery->payload ?? [];
            $text = match ($delivery->type) {
                'trial_ending_24h' => 'Ваш trial Tender Finder закончится примерно через 24 часа. После окончания мониторинги будут заморожены.',
                'trial_ending_3h' => 'Ваш trial Tender Finder закончится примерно через 3 часа. После окончания мониторинги будут заморожены.',
                'tender_deadline' => "Срок подачи заявки приближается: {$payload['title']}\nПодать до {$payload['deadline']}\n{$payload['url']}",
                'tender_action' => "На сегодня запланировано действие по тендеру: {$payload['title']}\n{$payload['url']}",
                'tender_change' => $this->changesText($payload),
                'tender_digest' => $this->digestText($payload),
                default => "Новый подходящий тендер: {$payload['title']}\n{$payload['url']}",
            };

            $bot->sendMessage($delivery->user->telegram_id, $text);
            $delivery->forceFill(['status' => NotificationStatus::Sent, 'sent_at' => now()])->save();
        } catch (Throwable $exception) {
            $delivery->forceFill([
                'status' => NotificationStatus::Failed,
                'failed_at' => now(),
                'failure_code' => 'telegram_delivery_failed',
            ])->save();

            throw $exception;
        }
    }

    private function followUpStillDue(NotificationDelivery $delivery): bool
    {
        $state = TenderUserState::query()->with(['user', 'tender'])
            ->where('user_id', $delivery->user_id)->where('tender_id', $delivery->tender_id)->first();
        if ($state === null || ! app(TenderFollowUpService::class)->eligible($state)) {
            return false;
        }
        $payload = $delivery->payload ?? [];
        $timezone = NotificationPreference::query()->where('user_id', $state->user_id)->value('timezone') ?? 'Europe/Moscow';

        return match ($delivery->type) {
            'tender_deadline' => $state->deadline_reminders_enabled && $state->tender->deadline_at?->isFuture()
                && $state->tender->deadline_at->toAtomString() === ($payload['deadline_at'] ?? null)
                && (($payload['threshold'] ?? 24) === 24 || now()->diffInHours($state->tender->deadline_at, false) > 24),
            'tender_action' => $state->action_reminder_enabled && $state->next_action_on?->format('Y-m-d') === ($payload['action_on'] ?? null)
                && now($timezone)->format('Y-m-d') === ($payload['action_on'] ?? null),
            'tender_change' => $state->watch_changes && $state->watch_started_at !== null && $state->watch_started_at->lte($delivery->created_at),
            default => false,
        };
    }

    /** @param array<string, mixed> $payload */
    private function changesText(array $payload): string
    {
        $labels = ['deadline_at' => 'Срок подачи', 'budget_amount' => 'Цена', 'currency' => 'Валюта', 'stage' => 'Статус'];
        $text = "Изменения в закупке: {$payload['title']}";
        foreach ($payload['changes'] ?? [] as $field => $change) {
            $text .= "\n".($labels[$field] ?? $field).': '.$change['before'].' → '.$change['after'];
        }

        return mb_substr($text, 0, 3000)."\n{$payload['url']}";
    }

    /** @param array<string, mixed> $payload */
    private function digestText(array $payload): string
    {
        $count = max(0, (int) ($payload['count'] ?? 0));
        $cards = '';

        foreach (is_array($payload['tenders'] ?? null) ? $payload['tenders'] : [] as $card) {
            if (! is_array($card)) {
                continue;
            }

            $title = mb_substr((string) ($card['title'] ?? 'Закупка'), 0, 240);
            $url = (string) ($card['url'] ?? '');
            $cards .= ($cards === '' ? '' : "\n\n")."• {$title}".($url !== '' ? "\n{$url}" : '');
        }

        $header = "Дайджест Tender Finder: {$count} новых совпадений за сутки.";

        return mb_substr($header.($cards !== '' ? "\n\n{$cards}" : ''), 0, 3900);
    }
}
