<?php

namespace App\Services;

use App\Models\SubscriptionRenewal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class YooKassaClient
{
    private const API_BASE_URL = 'https://api.yookassa.ru/v3';

    /**
     * Low-level transport only. The caller must build an approved order and
     * receipt before passing the request body to YooKassa.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    public function createPayment(array $payment, string $idempotenceKey): array
    {
        if (array_key_exists('payment_method_id', $payment)) {
            throw new YooKassaApiException('yookassa_renewal_context_required');
        }

        return $this->sendNewPayment($payment, $idempotenceKey);
    }

    /**
     * The future billing caller must supply a verified saved method and receipt.
     * All saved-method requests share the lock used by /cancel and web cancellation.
     * Returns null if an old queued attempt is no longer authorized.
     *
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>|null
     */
    public function createRenewalPayment(SubscriptionRenewal $renewal, array $payment, string $idempotenceKey): ?array
    {
        return app(SubscriptionRenewalService::class)->withChargeLock($renewal, function (SubscriptionRenewal $current) use ($payment, $idempotenceKey): array {
            if (! is_string($payment['payment_method_id'] ?? null) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $payment['payment_method_id']) !== 1) {
                throw new YooKassaApiException('yookassa_invalid_saved_method');
            }
            $payment['amount'] = ['value' => sprintf('%d.%02d', intdiv($current->amount_minor, 100), $current->amount_minor % 100), 'currency' => $current->currency];
            $payment['metadata'] = ['renewal_id' => $current->id, 'subscription_id' => (string) $current->subscription_id];

            return $this->sendNewPayment($payment, $idempotenceKey);
        });
    }

    /** @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function sendNewPayment(array $payment, string $idempotenceKey): array
    {
        // Retired by the owner: even a stale flag cannot create an initial or recurring payment.
        throw new YooKassaApiException('yookassa_new_payments_disabled');
    }

    /**
     * Reads remain available when new payments are switched off, so an
     * existing transaction can still be verified during incident handling.
     *
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $paymentId) !== 1) {
            throw new YooKassaApiException('yookassa_invalid_payment_id');
        }

        return $this->send('GET', '/payments/'.$paymentId);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        try {
            $request = $this->request()->withHeaders($headers);
            $response = $method === 'POST'
                ? $request->post(self::API_BASE_URL.$path, $body ?? [])
                : $request->get(self::API_BASE_URL.$path);
        } catch (ConnectionException) {
            throw new YooKassaApiException('yookassa_network_unavailable', true);
        }

        if (! $response->successful()) {
            throw new YooKassaApiException(
                'yookassa_http_'.$response->status(),
                $response->status() === 429 || $response->serverError(),
            );
        }

        return $this->jsonObject($response);
    }

    private function request(): PendingRequest
    {
        $shopId = config('tender.payments.yookassa.shop_id');
        $secretKey = config('tender.payments.yookassa.secret_key');

        if (! is_string($shopId) || trim($shopId) === ''
            || ! is_string($secretKey) || trim($secretKey) === '') {
            throw new YooKassaApiException('yookassa_credentials_missing');
        }

        $timeout = max(1, (int) config('tender.payments.yookassa.request_timeout_seconds', 15));

        return Http::acceptJson()
            ->asJson()
            ->withBasicAuth($shopId, $secretKey)
            ->connectTimeout(min(5, $timeout))
            ->timeout($timeout);
    }

    /** @return array<string, mixed> */
    private function jsonObject(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data) || array_is_list($data)) {
            throw new YooKassaApiException('yookassa_invalid_response', true);
        }

        return $data;
    }
}
