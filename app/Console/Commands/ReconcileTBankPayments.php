<?php

namespace App\Console\Commands;

use App\Services\TBankApiException;
use App\Services\TBankPaymentService;
use App\Services\TBankReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ReconcileTBankPayments extends Command
{
    protected $signature = 'payments:reconcile-tbank {--limit=10} {--attempt=} {--retry-review}';

    protected $description = 'Verify existing T-Bank payments and reconcile inbox; never create a payment';

    public function handle(TBankReconciliationService $reconciler, TBankPaymentService $payments): int
    {
        if (config('tender.payments.tbank.reconciliation_enabled') !== true) {
            $this->info('Сверка Т-Банка выключена.');

            return self::SUCCESS;
        }
        $id = $this->option('attempt');
        if ($id !== null) {
            if (! Str::isUuid($id)) {
                $this->error('Нужен UUID платёжной попытки.');

                return self::FAILURE;
            }
            try {
                $attempt = $payments->reconcile($id, (bool) $this->option('retry-review'));
                $this->line('Состояние: '.$attempt->state.'; код: '.($attempt->failure_code ?? 'none'));

                return self::SUCCESS;
            } catch (TBankApiException $exception) {
                $this->error($exception->failureCode);

                return self::FAILURE;
            }
        }
        $counts = $reconciler->run((int) $this->option('limit'));
        $this->line('Платежей: '.$counts['checked'].'; событий: '.$counts['events'].'; проверка: '.$counts['review'].'; ошибок: '.$counts['errors']);

        return $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
