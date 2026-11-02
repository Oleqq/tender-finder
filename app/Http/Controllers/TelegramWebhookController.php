<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Services\TelegramBotClient;
use App\Services\TelegramCommands;
use App\Services\TelegramConsentService;
use App\Telegram\TelegramDeliveryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('tender.telegram.webhook_secret');
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($secret) || $secret === '' || ! is_string($providedSecret) || ! hash_equals($secret, $providedSecret)) {
            abort(403);
        }

        $validated = $request->validate([
            'update_id' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'array'],
            'message.text' => ['nullable', 'string', 'max:4096'],
            'message.chat.id' => ['nullable'],
            'message.chat.type' => ['nullable', 'string', 'max:32'],
            'message.from.id' => ['nullable', 'integer', 'min:1'],
            'callback_query' => ['nullable', 'array'],
            'callback_query.id' => ['required_with:callback_query', 'string', 'max:128'],
            'callback_query.data' => ['nullable', 'string', 'max:64'],
            'callback_query.from.id' => ['required_with:callback_query', 'integer', 'min:1'],
            'callback_query.from.is_bot' => ['nullable', 'boolean'],
            'callback_query.message.chat.id' => ['nullable'],
            'callback_query.message.chat.type' => ['nullable', 'string', 'max:32'],
            'message.successful_payment' => ['nullable', 'array'],
            'message.successful_payment.currency' => ['nullable', 'string', 'max:8'],
            'message.successful_payment.total_amount' => ['nullable', 'integer', 'min:1'],
            'message.successful_payment.invoice_payload' => ['nullable', 'string', 'max:128'],
            'message.successful_payment.telegram_payment_charge_id' => ['nullable', 'string', 'max:128'],
            'message.successful_payment.provider_payment_charge_id' => ['nullable', 'string', 'max:128'],
            'message.successful_payment.subscription_expiration_date' => ['nullable', 'integer'],
            'message.successful_payment.is_recurring' => ['nullable', 'boolean'],
            'message.successful_payment.is_first_recurring' => ['nullable', 'boolean'],
            'message.refunded_payment' => ['nullable', 'array'],
            'message.refunded_payment.currency' => ['nullable', 'string', 'max:8'],
            'message.refunded_payment.total_amount' => ['nullable', 'integer', 'min:1'],
            'message.refunded_payment.invoice_payload' => ['nullable', 'string', 'max:128'],
            'message.refunded_payment.telegram_payment_charge_id' => ['nullable', 'string', 'max:128'],
            'subscription' => ['nullable', 'array'],
            'subscription.user.id' => ['nullable'],
            'subscription.invoice_payload' => ['nullable', 'string', 'max:128'],
            'subscription.state' => ['nullable', 'string', 'in:active,canceled,failed'],
            'pre_checkout_query' => ['nullable', 'array'],
            'pre_checkout_query.id' => ['nullable', 'string', 'max:128'],
            'pre_checkout_query.from.id' => ['nullable'],
            'pre_checkout_query.currency' => ['nullable', 'string', 'max:8'],
            'pre_checkout_query.total_amount' => ['nullable', 'integer', 'min:1'],
            'pre_checkout_query.invoice_payload' => ['nullable', 'string', 'max:128'],
        ]);

        $created = false;
        $callbackAnswer = 'Запрос уже обработан.';
        $type = $this->updateType($validated);
        $isPaymentUpdate = in_array($type, ['successful_payment', 'refunded_payment', 'subscription'], true);
        $paymentPayload = match ($type) {
            'successful_payment' => $this->safePaymentPayload($validated['message']['successful_payment']),
            'refunded_payment' => $this->safePaymentPayload($validated['message']['refunded_payment']),
            'subscription' => $this->safeSubscriptionPayload($validated['subscription']),
            default => null,
        };

        DB::transaction(function () use ($validated, $type, $paymentPayload, &$created, &$callbackAnswer): void {
            $now = now();
            $created = DB::table('telegram_updates')->insertOrIgnore([
                'telegram_update_id' => $validated['update_id'],
                'type' => $type,
                'status' => in_array($type, ['message', 'callback_query'], true) ? 'received' : 'ignored',
                'payment_payload' => $paymentPayload === null ? null : json_encode($paymentPayload, JSON_THROW_ON_ERROR),
                'received_at' => $now,
                'processed_at' => in_array($type, ['message', 'callback_query'], true) ? null : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;

            if (! $created) {
                return;
            }

            $update = TelegramUpdate::query()
                ->where('telegram_update_id', $validated['update_id'])
                ->firstOrFail();

            if ($type === 'callback_query') {
                $callbackAnswer = app(TelegramConsentService::class)->accept($validated['callback_query'], (int) $validated['update_id']);
                $update->forceFill(['status' => 'processed', 'processed_at' => now()])->save();

                return;
            }

            $command = $this->commandFrom($validated['message']['text'] ?? null);
            $chatId = $validated['message']['chat']['id'] ?? null;

            if ($type !== 'message') {
                return;
            }

            if ($command === null || ! (is_int($chatId) || (is_string($chatId) && preg_match('/\A-?\d+\z/', $chatId)))) {
                $update->forceFill(['status' => 'ignored', 'processed_at' => now()])->save();

                return;
            }

            $actor = data_get($validated, 'message.chat.type') === 'private' ? data_get($validated, 'message.from.id') : null;
            ProcessTelegramUpdate::dispatch($validated['update_id'], $command, (string) $chatId, $actor === null ? null : (string) $actor)->afterCommit();
        });

        if ($type === 'callback_query') {
            try {
                app(TelegramBotClient::class)->answerCallbackQuery($validated['callback_query']['id'], $callbackAnswer);
            } catch (TelegramDeliveryException $exception) {
                if ($exception->retryable) {
                    throw $exception;
                }
                // An expired callback cannot be acknowledged, but the durable
                // consent/update must not be replayed indefinitely by Telegram.
                Log::warning('telegram.consent_callback_ack_failed', [
                    'update_id' => $validated['update_id'], 'failure_code' => $exception->failureCode,
                ]);
            }
        }

        if ($type === 'pre_checkout_query') {
            $queryId = $validated['pre_checkout_query']['id'] ?? null;
            if (is_string($queryId) && $queryId !== '') {
                app(TelegramBotClient::class)->answerPreCheckoutQuery(
                    $queryId,
                    false,
                    'Оплата в Telegram больше недоступна.',
                );
            }
        }

        if ($isPaymentUpdate && $created) {
            Log::error('telegram.payment_update_received_after_retirement', [
                'update_id' => $validated['update_id'],
                'type' => $type,
            ]);
        }

        return response()->json(['ok' => true, 'duplicate' => ! $created]);
    }

    private function commandFrom(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($text), 2) ?: [];
        $command = strtolower((string) ($parts[0] ?? ''));
        $command = (string) preg_replace('/@[^\s]+\z/u', '', $command);

        return TelegramCommands::accepts($command) ? $command : null;
    }

    /** @param array<string, mixed> $update */
    private function updateType(array $update): string
    {
        if (isset($update['callback_query'])) {
            return 'callback_query';
        }
        if (isset($update['pre_checkout_query'])) {
            return 'pre_checkout_query';
        }

        if (isset($update['message']['successful_payment'])) {
            return 'successful_payment';
        }

        if (isset($update['message']['refunded_payment'])) {
            return 'refunded_payment';
        }

        if (isset($update['subscription'])) {
            return 'subscription';
        }

        return isset($update['message']) ? 'message' : 'unsupported';
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function safePaymentPayload(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'currency', 'total_amount', 'invoice_payload', 'telegram_payment_charge_id',
            'provider_payment_charge_id', 'subscription_expiration_date',
            'is_recurring', 'is_first_recurring',
        ]));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function safeSubscriptionPayload(array $data): array
    {
        return [
            'user' => ['id' => data_get($data, 'user.id')],
            'invoice_payload' => data_get($data, 'invoice_payload'),
            'state' => data_get($data, 'state'),
        ];
    }
}
