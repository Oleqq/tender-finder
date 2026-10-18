<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\TelegramStarsPaymentException;
use App\Services\TelegramStarsPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminBillingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('AdminBilling', [
            'payments' => Payment::query()->with(['user', 'plan'])
                ->where('provider', 'telegram_stars')->latest('id')->limit(50)->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'user' => $payment->user?->telegram_username ?: $payment->user?->name,
                    'plan' => $payment->plan?->name,
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                    'created_at' => $payment->created_at?->toAtomString(),
                    'paid_at' => $payment->paid_at?->toAtomString(),
                    'refundable' => $payment->status === PaymentStatus::Paid,
                ])->all(),
        ]);
    }

    public function refund(Request $request, Payment $payment, TelegramStarsPaymentService $stars): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        if ($payment->status !== PaymentStatus::Paid || $payment->provider !== 'telegram_stars') {
            return back()->withErrors(['billing' => 'Этот платёж нельзя вернуть.']);
        }

        $eventId = DB::table('billing_events')->insertGetId([
            'payment_id' => $payment->id,
            'actor_user_id' => $request->user()->id,
            'type' => 'refund',
            'status' => 'requested',
            'reason' => $data['reason'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $stars->refund($payment);
            DB::table('billing_events')->where('id', $eventId)
                ->update(['status' => 'completed', 'updated_at' => now()]);
        } catch (TelegramStarsPaymentException $exception) {
            DB::table('billing_events')->where('id', $eventId)
                ->update(['status' => 'failed', 'updated_at' => now()]);

            return back()->withErrors(['billing' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            DB::table('billing_events')->where('id', $eventId)
                ->update(['status' => 'needs_reconciliation', 'updated_at' => now()]);
            report($exception);

            return back()->withErrors(['billing' => 'Статус возврата нужно сверить с Telegram.']);
        }

        return back()->with('status', 'Возврат отправлен в Telegram и записан.');
    }
}
