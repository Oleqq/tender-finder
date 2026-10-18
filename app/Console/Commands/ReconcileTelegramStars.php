<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\TelegramBotClient;
use Illuminate\Console\Command;

class ReconcileTelegramStars extends Command
{
    protected $signature = 'billing:reconcile-stars {--max=1000 : Maximum Telegram transactions to inspect in API order}';

    protected $description = 'Read-only comparison of Telegram Stars invoice transactions and local payments.';

    public function handle(TelegramBotClient $bot): int
    {
        $max = filter_var($this->option('max'), FILTER_VALIDATE_INT);
        if ($max === false || $max < 1 || $max > 10_000) {
            $this->error('Use --max between 1 and 10000.');

            return self::INVALID;
        }

        $inspected = 0;
        $ours = 0;
        $anomalies = 0;

        for ($offset = 0; $offset < $max; $offset += 100) {
            $limit = min(100, $max - $offset);
            $transactions = $bot->getStarTransactions($offset, $limit);
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
                        $this->warn('Incoming charge needs review: '.substr(hash('sha256', $chargeId), 0, 12));
                    }
                }

                if (is_array($receiver) && ($receiver['type'] ?? null) === 'user'
                    && $payment !== null && $payment->status !== PaymentStatus::Refunded) {
                    $anomalies++;
                    $this->warn('Refund needs review for local payment #'.$payment->id);
                }
            }

            if (count($transactions) < $limit) {
                break;
            }
        }

        $this->info("Inspected {$inspected} Telegram transactions; {$ours} Tender Finder invoices; {$anomalies} discrepancies.");

        return $anomalies === 0 ? self::SUCCESS : self::FAILURE;
    }
}
