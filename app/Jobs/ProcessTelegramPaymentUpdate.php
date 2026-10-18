<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\TelegramStarsPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessTelegramPaymentUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public readonly int $updateId) {}

    public function handle(TelegramStarsPaymentService $stars): void
    {
        $update = TelegramUpdate::query()->where('telegram_update_id', $this->updateId)->firstOrFail();
        if ($update->status === 'processed') {
            return;
        }

        $update->increment('payment_attempts');

        try {
            $payload = $update->payment_payload;
            if (! is_array($payload)) {
                throw new \RuntimeException('Payment update payload is missing.');
            }

            switch ($update->type) {
                case 'successful_payment':
                    $stars->settleSuccessfulPayment($payload);
                    break;
                case 'refunded_payment':
                    $stars->recordRefund($payload);
                    break;
                case 'subscription':
                    $stars->recordSubscriptionUpdate($payload);
                    break;
                default:
                    throw new \RuntimeException('Unknown payment update type.');
            }

            $update->forceFill([
                'status' => 'processed',
                'failure_code' => null,
                'processed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $update->forceFill([
                'status' => 'failed',
                'failure_code' => 'payment_processing_failed',
                'processed_at' => now(),
            ])->save();
            throw $exception;
        }
    }
}
