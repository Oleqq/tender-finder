<?php

namespace App\Services;

use App\Telegram\TelegramDeliveryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BillingIncidentAlerts
{
    public function __construct(private readonly TelegramBotClient $bot) {}

    public function notify(string $key, string $message): bool
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            Log::error('billing.alert.recipients_missing');

            return false;
        }

        $allDelivered = true;
        foreach ($recipients as $recipient) {
            $fingerprint = substr(hash('sha256', $recipient), 0, 12);
            $cacheKey = 'billing:alert:'.hash('sha256', $recipient.'|'.$key);
            $sendingKey = $cacheKey.':sending';
            $reserved = false;

            try {
                if (Cache::has($cacheKey) || ! Cache::add($sendingKey, true, now()->addMinute())) {
                    continue;
                }
                $reserved = true;
            } catch (Throwable $exception) {
                Log::error('billing.alert.deduplication_unavailable', [
                    'recipient_fingerprint' => $fingerprint,
                    'exception_type' => $exception::class,
                ]);

                // Continue delivery if the cache is unavailable; duplicates are possible.
            }

            try {
                $this->bot->sendMessage($recipient, $message, ['screen' => 'plans']);
                if ($reserved) {
                    try {
                        Cache::put($cacheKey, true, now()->addHours(6));
                    } catch (Throwable $exception) {
                        Log::error('billing.alert.deduplication_unavailable', [
                            'recipient_fingerprint' => $fingerprint,
                            'exception_type' => $exception::class,
                        ]);
                    }
                }
                Log::info('billing.alert.delivered', [
                    'incident_fingerprint' => substr(hash('sha256', $key), 0, 12),
                    'recipient_fingerprint' => $fingerprint,
                ]);
            } catch (Throwable $exception) {
                $allDelivered = false;
                Log::error('billing.alert.delivery_failed', [
                    'incident_fingerprint' => substr(hash('sha256', $key), 0, 12),
                    'recipient_fingerprint' => $fingerprint,
                    'failure_code' => $exception instanceof TelegramDeliveryException
                        ? $exception->failureCode : 'unexpected_error',
                ]);
            } finally {
                if ($reserved) {
                    try {
                        Cache::forget($sendingKey);
                    } catch (Throwable) {
                        // The reservation expires after one minute.
                    }
                }
            }
        }

        return $allDelivered;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $configured = [config('tender.telegram.owner_id')];
        $additional = config('tender.telegram.superadmin_ids');
        if (is_string($additional)) {
            $configured = [...$configured, ...preg_split('/[\s,;]+/', $additional)];
        }

        return array_values(array_unique(array_filter(
            $configured,
            static fn (mixed $id): bool => is_string($id) && preg_match('/^\d+$/', $id) === 1,
        )));
    }
}
