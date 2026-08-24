<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $updateId,
        public readonly string $command,
        public readonly string $chatId,
    ) {}

    public function handle(TelegramBotClient $bot): void
    {
        $update = TelegramUpdate::query()->where('telegram_update_id', $this->updateId)->first();

        if ($update === null || $update->status === 'processed') {
            return;
        }

        $text = match ($this->command) {
            '/start' => 'Добро пожаловать в Tender Finder. Откройте Mini App кнопкой меню, чтобы настроить мониторинг.',
            '/help' => 'Tender Finder помогает отслеживать подходящие закупки. Откройте Mini App кнопкой меню для начала.',
            default => null,
        };

        if ($text === null) {
            $update->forceFill(['status' => 'ignored', 'processed_at' => now()])->save();

            return;
        }

        try {
            $bot->sendMessage($this->chatId, $text);
            $update->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'failure_code' => null,
            ])->save();
        } catch (Throwable $exception) {
            $update->forceFill(['status' => 'failed', 'failure_code' => 'bot_delivery_failed'])->save();

            throw $exception;
        }
    }
}
