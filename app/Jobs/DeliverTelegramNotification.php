<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\NotificationDelivery;
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

    public function handle(TelegramBotClient $bot): void
    {
        $delivery = NotificationDelivery::query()->with('user')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== NotificationStatus::Queued || $delivery->user->telegram_id === null) {
            return;
        }

        try {
            $payload = $delivery->payload ?? [];
            $text = $delivery->type === 'tender_digest'
                ? 'За этот час найдено больше 20 совпадений. Откройте Mini App: там будет сводка до 10 карточек.'
                : "Новый подходящий тендер: {$payload['title']}\n{$payload['url']}";

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
