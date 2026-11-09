<?php

namespace App\Services;

use App\Models\TBankNotification;
use App\Models\TBankPaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class TBankReconciliationService
{
    /** @return array{checked: int, events: int, review: int, errors: int} */
    public function run(int $limit = 10): array
    {
        $counts = ['checked' => 0, 'events' => 0, 'review' => 0, 'errors' => 0];
        if (config('tender.payments.tbank.reconciliation_enabled') !== true) {
            return $counts;
        }
        $limit = max(1, min(50, $limit));
        $ids = TBankPaymentAttempt::query()->whereIn('state', ['initializing', 'ambiguous', 'pending'])
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->orderBy('next_check_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $attempt = app(TBankPaymentService::class)->reconcile((string) $id);
                $counts['checked']++;
                $counts['review'] += (int) ($attempt->state === 'review');
            } catch (Throwable) {
                // A committed lease recovers after expiry if the process or database fails.
                $counts['errors']++;
                Log::warning('payments.tbank.reconciliation_failed', ['attempt_id' => $id, 'failure_code' => 'local_reconciliation_failed']);
            }
        }
        $events = TBankNotification::query()->whereIn('state', ['received', 'retry', 'processing'])
            ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($events as $id) {
            try {
                $state = $this->process((int) $id);
                $counts['events'] += (int) ($state !== null);
                $counts['review'] += (int) ($state === 'review');
            } catch (Throwable) {
                $counts['errors']++;
                Log::warning('payments.tbank.inbox_processing_failed', ['notification_id' => $id, 'failure_code' => 'local_reconciliation_failed']);
            }
        }

        return $counts;
    }

    public function process(int $id): ?string
    {
        if (config('tender.payments.tbank.reconciliation_enabled') !== true) {
            return null;
        }
        $event = DB::transaction(function () use ($id): ?TBankNotification {
            $event = TBankNotification::query()->lockForUpdate()->findOrFail($id);
            if (! in_array($event->state, ['received', 'retry', 'processing'], true) || $event->next_retry_at?->isFuture()) {
                return null;
            }
            $event->forceFill(['state' => 'processing', 'attempts' => $event->attempts + 1,
                'processing_token' => (string) Str::uuid(), 'next_retry_at' => now()->addMinutes(5)])->save();

            return $event;
        });
        if ($event === null) {
            return null;
        }
        $attempt = TBankPaymentAttempt::query()->where('order_id', $event->order_id)->where('terminal_key', $event->terminal_key)->first();
        if ($attempt === null || $attempt->payment_id === null) {
            return $this->retry($event, $attempt === null ? 'tbank_unknown_order' : 'tbank_init_not_bound');
        }
        if ($attempt->payment_id !== $event->payment_id || $attempt->amount_minor !== $event->amount_minor) {
            return $this->finish($event, 'rejected', 'tbank_notification_mismatch');
        }
        try {
            // The signed envelope is only a wake-up. Bank state is always read again.
            $attempt = app(TBankPaymentService::class)->reconcile($attempt->id, true);
        } catch (Throwable) {
            return $this->retry($event, 'local_reconciliation_failed');
        }
        if ($attempt->check_token !== null) {
            return $this->retry($event, 'tbank_check_busy');
        }
        if ($attempt->state === 'review') {
            return $this->finish($event, 'review', $attempt->failure_code);
        }
        if ($attempt->failure_code !== null) {
            return $this->retry($event, $attempt->failure_code);
        }

        return $this->finish($event, 'processed');
    }

    private function retry(TBankNotification $event, string $code): string
    {
        if ($event->attempts >= 12) {
            return $this->finish($event, 'review', $code);
        }
        TBankNotification::query()->whereKey($event->id)->where('processing_token', $event->processing_token)
            ->where('state', 'processing')->update(['state' => 'retry', 'failure_code' => $code, 'processing_token' => null,
                'next_retry_at' => now()->addSeconds(min(3600, 30 * (2 ** min($event->attempts, 7))))]);

        return $event->refresh()->state;
    }

    private function finish(TBankNotification $event, string $state, ?string $code = null): string
    {
        TBankNotification::query()->whereKey($event->id)->where('processing_token', $event->processing_token)
            ->where('state', 'processing')->update(['state' => $state, 'failure_code' => $code,
                'processing_token' => null, 'next_retry_at' => null, 'processed_at' => now()]);

        return $event->refresh()->state;
    }
}
