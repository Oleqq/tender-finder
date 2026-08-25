<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\NotificationDelivery;
use App\Services\AccessService;
use App\Services\TelegramBotClient;
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

        try {
            $payload = $delivery->payload ?? [];
            $text = match ($delivery->type) {
                'trial_ending_24h' => 'Ваш trial Tender Finder закончится примерно через 24 часа. После окончания мониторинги будут заморожены.',
                'trial_ending_3h' => 'Ваш trial Tender Finder закончится примерно через 3 часа. После окончания мониторинги будут заморожены.',
                'tender_digest' => 'За этот час найдено больше 20 совпадений. Откройте Mini App: там будет сводка до 10 карточек.',
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
}
