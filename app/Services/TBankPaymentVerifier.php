<?php

namespace App\Services;

final class TBankPaymentVerifier
{
    public function __construct(private readonly TBankClient $client) {}

    /** Read-only. Expected values must come from the stored payment attempt, never a browser/webhook. */
    public function isConfirmed(string $paymentId, string $orderId, int $amountMinor): bool
    {
        return $this->verifiedState($paymentId, $orderId, $amountMinor)['Status'] === 'CONFIRMED';
    }

    /** @return array<string, mixed> */
    public function verifiedState(string $paymentId, string $orderId, int $amountMinor): array
    {
        if ($amountMinor <= 0) {
            throw new TBankApiException('tbank_invalid_expected_amount');
        }
        $payment = $this->client->getState($paymentId);
        if ((string) ($payment['PaymentId'] ?? '') !== $paymentId
            || ($payment['OrderId'] ?? null) !== $orderId
            || ! is_int($payment['Amount'] ?? null) || $payment['Amount'] !== $amountMinor) {
            throw new TBankApiException('tbank_payment_mismatch');
        }

        // AUTHORIZED is a hold, not a completed purchase; refunds also must not grant access.
        if (! is_string($payment['Status'] ?? null) || preg_match('/\A[A-Z0-9_]{1,40}\z/D', $payment['Status']) !== 1) {
            throw new TBankApiException('tbank_invalid_response');
        }

        return $payment;
    }
}
