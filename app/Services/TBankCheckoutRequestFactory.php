<?php

namespace App\Services;

use App\Models\SubscriptionOrder;
use Carbon\CarbonInterface;

/** Builds a one-off RUB payment from an already approved, server-owned order snapshot. */
final class TBankCheckoutRequestFactory
{
    /** @return array<string, mixed> */
    public function make(SubscriptionOrder $order, string $receiptEmail): array
    {
        if ($order->provider !== 'tbank' || $order->is_demo || $order->currency !== 'RUB'
            || $order->status !== 'pending' || $order->amount_minor <= 0
            || ! ($order->getAttribute('expires_at') instanceof CarbonInterface)
            || $order->expires_at->lt(now()->addMinute())
            || $order->expires_at->gt(now()->addDays(90))
            || ($order->terms['amount_minor'] ?? null) !== $order->amount_minor
            || ($order->terms['code'] ?? null) !== $order->plan_code
            || ($order->terms['duration_months'] ?? null) !== $order->duration_months
            || ! isset(SubscriptionCatalog::OFFERS[$order->plan_code])
            || ! in_array($order->duration_months, [1, 3, 12], true)
            || ($order->terms['auto_renew'] ?? false) !== false
            || preg_match('/\A[A-Za-z0-9-]{1,50}\z/D', $order->id) !== 1
            || strlen($receiptEmail) > 64
            || filter_var($receiptEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new TBankApiException('tbank_invalid_order');
        }
        // No guessed fiscal defaults: the accountant must approve these settings first.
        $taxation = config('tender.payments.tbank.receipt.taxation');
        $tax = config('tender.payments.tbank.receipt.tax');
        $method = config('tender.payments.tbank.receipt.payment_method');
        $object = config('tender.payments.tbank.receipt.payment_object');
        if (! in_array($taxation, ['osn', 'usn_income', 'usn_income_outcome', 'esn', 'patent'], true)
            || ! in_array($tax, ['none', 'vat0', 'vat5', 'vat7', 'vat10', 'vat22', 'vat105', 'vat107', 'vat110', 'vat122'], true)
            || ! in_array($method, ['full_payment', 'full_prepayment', 'prepayment', 'advance'], true)
            || ! in_array($object, ['service', 'payment', 'intellectual_activity'], true)) {
            throw new TBankApiException('tbank_receipt_settings_missing');
        }
        $base = rtrim((string) config('tender.payments.tbank.public_base_url'), '/');
        $url = parse_url($base);
        if ($url === false || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || isset($url['port']) || ! in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new TBankApiException('tbank_invalid_public_url');
        }
        $name = 'TenderFinder '.SubscriptionCatalog::OFFERS[$order->plan_code]['name'].' — '.$order->duration_months.' мес.';

        return [
            'OrderId' => $order->id, 'Amount' => $order->amount_minor,
            'Description' => $name, 'PayType' => 'O', 'Language' => 'ru',
            'DATA' => ['OperationInitiatorType' => '0'],
            'RedirectDueDate' => $order->expires_at->toIso8601String(),
            'NotificationURL' => $base.'/payments/tbank/notifications',
            // Informational return only. Neither redirect is proof of payment.
            'SuccessURL' => $base.'/pricing', 'FailURL' => $base.'/pricing',
            'Receipt' => [
                'Email' => $receiptEmail, 'Taxation' => $taxation,
                'Items' => [[
                    'Name' => $name, 'Price' => $order->amount_minor, 'Quantity' => 1,
                    'Amount' => $order->amount_minor, 'Tax' => $tax,
                    'PaymentMethod' => $method, 'PaymentObject' => $object,
                ]],
            ],
        ];
    }
}
