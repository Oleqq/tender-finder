<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\BillingIncidentAlerts;
use App\Services\TelegramBotClient;
use App\Telegram\TelegramDeliveryException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileTelegramStars extends Command
{
    protected $signature = 'billing:reconcile-stars {--max=1000 : Maximum Telegram transactions to inspect in API order}';

    protected $description = 'Read-only comparison of Telegram Stars invoice transactions and local payments.';

    public function handle(TelegramBotClient $bot, BillingIncidentAlerts $alerts): int
    {
        $max = filter_var($this->option('max'), FILTER_VALIDATE_INT);
        if ($max === false || $max < 1 || $max > 10_000) {
            $this->error('Use --max between 1 and 10000.');

            return self::INVALID;
        }

        $inspected = 0;
        $ours = 0;
        $anomalies = 0;
        $anomalyFingerprints = [];

        for ($offset = 0; $offset < $max; $offset += 100) {
            $limit = min(100, $max - $offset);
            try {
                $transactions = $bot->getStarTransactions($offset, $limit);
            } catch (TelegramDeliveryException $exception) {
                Log::error('billing.stars.reconciliation_unavailable', [
                    'failure_code' => $exception->failureCode,
                    'inspected' => $inspected,
                    'max' => $max,
                ]);
                $alerts->notify(
                    'reconciliation-unavailable:'.$exception->failureCode,
                    '⚠️ Tender Finder: сверка платежей Stars не завершилась ('
                        .$exception->failureCode."). Проверено транзакций: {$inspected}. Проверьте API и повторите сверку.",
                );
                $this->error('Telegram Stars reconciliation could not finish: '.$exception->failureCode);

                return self::FAILURE;
            }
            if ($transactions === []) {
                break;
            }

            foreach ($transactions as $transaction) {
                $inspected++;
                $chargeId = $transaction['id'] ?? null;
                if (! is_string($chargeId) || $chargeId === '') {
                    continue;
                }

                $source = $transaction['source'] ?? null;
                $receiver = $transaction['receiver'] ?? null;
                $isOurInvoice = is_array($source)
                    && ($source['type'] ?? null) === 'user'
                    && ($source['transaction_type'] ?? null) === 'invoice_payment'
                    && is_string($source['invoice_payload'] ?? null)
                    && str_starts_with($source['invoice_payload'], 'tf-stars:');
                /** @var Payment|null $payment */
                $payment = Payment::query()->where('provider', 'telegram_stars')
                    ->where('telegram_payment_charge_id', $chargeId)->first();

                if ($isOurInvoice) {
                    $ours++;
                    if ($payment === null || $payment->amount !== ($transaction['amount'] ?? null)
                        || ! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true)) {
                        $anomalies++;
                        $fingerprint = substr(hash('sha256', $chargeId), 0, 12);
                        $anomalyFingerprints[] = $fingerprint;
                        Log::warning('billing.stars.reconciliation_discrepancy', [
                            'kind' => 'incoming_charge',
                            'charge_fingerprint' => $fingerprint,
                            'payment_id' => $payment?->id,
                        ]);
                        $this->warn('Incoming charge needs review: '.$fingerprint);
                    }
                }

                if (is_array($receiver) && ($receiver['type'] ?? null) === 'user'
                    && $payment !== null && $payment->status !== PaymentStatus::Refunded) {
                    $anomalies++;
                    $anomalyFingerprints[] = substr(hash('sha256', $chargeId), 0, 12);
                    Log::warning('billing.stars.reconciliation_discrepancy', [
                        'kind' => 'refund_without_local_status',
                        'charge_fingerprint' => substr(hash('sha256', $chargeId), 0, 12),
                        'payment_id' => $payment->id,
                    ]);
                    $this->warn('Refund needs review for local payment #'.$payment->id);
                }
            }

            if (count($transactions) < $limit) {
                break;
            }
        }

        $summary = [
            'inspected' => $inspected,
            'invoices' => $ours,
            'discrepancies' => $anomalies,
            'max' => $max,
        ];
        if ($anomalies === 0) {
            Log::info('billing.stars.reconciliation_finished', $summary);
        } else {
            Log::warning('billing.stars.reconciliation_finished', $summary);
            sort($anomalyFingerprints);
            $alerts->notify(
                'reconciliation-discrepancy:'.hash('sha256', implode(',', $anomalyFingerprints)),
                "⚠️ Tender Finder: сверка Stars нашла {$anomalies} расхождений среди {$inspected} транзакций. "
                    .'Проверьте журнал billing.stars.reconciliation_discrepancy и выполните ручной разбор.',
            );
        }
        $this->info("Inspected {$inspected} Telegram transactions; {$ours} Tender Finder invoices; {$anomalies} discrepancies.");

        return $anomalies === 0 ? self::SUCCESS : self::FAILURE;
    }
}
