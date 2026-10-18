<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramPaymentUpdate;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use App\Services\TelegramBotClient;
use App\Services\TelegramStarsPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $type = $this->updateType($validated);
        $durablePayment = in_array($type, ['successful_payment', 'refunded_payment', 'subscription'], true);
        $paymentPayload = match ($type) {
            'successful_payment' => $this->safePaymentPayload($validated['message']['successful_payment']),
            'refunded_payment' => $this->safePaymentPayload($validated['message']['refunded_payment']),
            'subscription' => $this->safeSubscriptionPayload($validated['subscription']),
            default => null,
        };

        DB::transaction(function () use ($validated, $type, $paymentPayload, &$created): void {
            $now = now();
            $created = DB::table('telegram_updates')->insertOrIgnore([
                'telegram_update_id' => $validated['update_id'],
                'type' => $type,
                'status' => 'received',
                'payment_payload' => $paymentPayload === null ? null : json_encode($paymentPayload, JSON_THROW_ON_ERROR),
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;

            if (! $created) {
                return;
            }

            $update = TelegramUpdate::query()
                ->where('telegram_update_id', $validated['update_id'])
                ->firstOrFail();

            $command = $this->commandFrom($validated['message']['text'] ?? null);
            $chatId = $validated['message']['chat']['id'] ?? null;

            if ($type === 'pre_checkout_query' || $paymentPayload !== null) {
                return;
            }

            if ($command === null || ! (is_int($chatId) || (is_string($chatId) && preg_match('/\A-?\d+\z/', $chatId)))) {
                $update->forceFill(['status' => 'ignored', 'processed_at' => now()])->save();

                return;
            }

            ProcessTelegramUpdate::dispatch($validated['update_id'], $command, (string) $chatId)->afterCommit();
        });

        $status = TelegramUpdate::query()->where('telegram_update_id', $validated['update_id'])->value('status');

        if ($type === 'pre_checkout_query' && $status !== 'processed') {
            $this->answerPreCheckout($validated['update_id'], $validated['pre_checkout_query']);
        }

        if ($durablePayment && $status !== 'processed') {
            ProcessTelegramPaymentUpdate::dispatch($validated['update_id']);
        }

        return response()->json(['ok' => true, 'duplicate' => ! $created]);
    }

    /** @param array<string, mixed> $query */
    private function answerPreCheckout(int $updateId, array $query): void
    {
        $queryId = $query['id'] ?? null;

        if (! is_string($queryId) || $queryId === '') {
            TelegramUpdate::query()->where('telegram_update_id', $updateId)
                ->update(['status' => 'failed', 'failure_code' => 'invalid_pre_checkout', 'processed_at' => now()]);

            return;
        }

        $error = app(TelegramStarsPaymentService::class)->validatePreCheckout($query);
        app(TelegramBotClient::class)->answerPreCheckoutQuery($queryId, $error === null, $error);

        TelegramUpdate::query()->where('telegram_update_id', $updateId)
            ->update([
                'status' => $error === null ? 'processed' : 'ignored',
                'failure_code' => $error === null ? null : 'pre_checkout_rejected',
                'processed_at' => now(),
            ]);
    }

    private function commandFrom(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $parts = preg_split('/\s+/u', trim($text), 2) ?: [];
        $command = strtolower((string) ($parts[0] ?? ''));
        $command = (string) preg_replace('/@[^\s]+\z/u', '', $command);

        if (in_array($command, ['/start', '/help'], true)) {
            return $command;
        }

        if ($command === '/paysupport') {
            return $command;
        }

        if ($command !== '/subscribe') {
            return null;
        }

        $plan = strtolower(trim((string) ($parts[1] ?? 'basic')));

        return in_array($plan, ['basic', 'pro'], true) ? "/subscribe:{$plan}" : null;
    }

    /** @param array<string, mixed> $update */
    private function updateType(array $update): string
    {
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
