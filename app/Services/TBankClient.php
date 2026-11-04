<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Transport only: no user checkout, access grants, refunds or recurring charges. */
final class TBankClient
{
    private const BASE_URL = 'https://securepay.tinkoff.ru/v2/';

    public function __construct(private readonly TBankToken $token) {}

    /** @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    public function initialize(array $payment): array
    {
        if (config('tender.payments.tbank.enabled') !== true) {
            throw new TBankApiException('tbank_new_payments_disabled');
        }
        // Only the server-side request builder should supply this payload.
        if (! is_int($payment['Amount'] ?? null) || $payment['Amount'] <= 0
            || ! is_string($payment['OrderId'] ?? null)
            || preg_match('/\A[A-Za-z0-9-]{1,50}\z/D', $payment['OrderId']) !== 1
            || ! is_array($payment['Receipt'] ?? null)
            || ($payment['PayType'] ?? null) !== 'O'
            || ($payment['DATA']['OperationInitiatorType'] ?? null) !== '0'
            || isset($payment['Recurrent']) || isset($payment['CustomerKey']) || isset($payment['RebillId'])) {
            throw new TBankApiException('tbank_invalid_payment_request');
        }

        return $this->send('Init', $payment);
    }

    /** Reads remain available after new sales are stopped.
     * @return array<string, mixed>
     */
    public function getState(string $paymentId): array
    {
        $this->assertPaymentId($paymentId);

        return $this->send('GetState', ['PaymentId' => $paymentId]);
    }

    /** Recover an ambiguous Init before retrying it; Init has no Idempotence-Key header.
     * @return array<string, mixed>
     */
    public function checkOrder(string $orderId): array
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,50}\z/D', $orderId) !== 1) {
            throw new TBankApiException('tbank_invalid_order_id');
        }

        return $this->send('CheckOrder', ['OrderId' => $orderId]);
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function send(string $method, array $body): array
    {
        $terminal = config('tender.payments.tbank.terminal_key');
        $password = config('tender.payments.tbank.password');
        if (! is_string($terminal) || trim($terminal) === ''
            || ! is_string($password) || trim($password) === '') {
            throw new TBankApiException('tbank_credentials_missing');
        }
        unset($body['Password'], $body['Token']);
        $body['TerminalKey'] = $terminal;
        $body['Token'] = $this->token->sign($body, $password);
        $timeout = max(1, min(60, (int) config('tender.payments.tbank.request_timeout_seconds', 15)));
        try {
            // Never automatically retry a money-related request or follow a redirect with credentials.
            $response = Http::acceptJson()->asJson()->withoutRedirecting()
                ->connectTimeout(min(5, $timeout))->timeout($timeout)
                ->post(self::BASE_URL.$method, $body);
        } catch (ConnectionException) {
            throw new TBankApiException('tbank_network_unavailable', $method !== 'Init');
        }
        if (! $response->successful()) {
            throw new TBankApiException('tbank_http_'.$response->status(), $method !== 'Init' && ($response->status() === 429 || $response->serverError()));
        }
        $data = $response->json();
        if (! is_array($data) || array_is_list($data) || ! is_bool($data['Success'] ?? null)) {
            throw new TBankApiException('tbank_invalid_response', $method !== 'Init');
        }
        if ($data['Success'] !== true || (string) ($data['ErrorCode'] ?? '') !== '0') {
            // Provider Message/Details can contain personal data; never attach them to exceptions.
            throw new TBankApiException('tbank_operation_rejected');
        }
        if (($data['TerminalKey'] ?? null) !== $terminal) {
            throw new TBankApiException('tbank_terminal_mismatch');
        }

        return $data;
    }

    private function assertPaymentId(string $paymentId): void
    {
        if (preg_match('/\A[0-9]{1,20}\z/D', $paymentId) !== 1) {
            throw new TBankApiException('tbank_invalid_payment_id');
        }
    }
}
