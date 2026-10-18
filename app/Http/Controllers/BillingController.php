<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionSource;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\ConsentService;
use App\Services\TelegramStarsPaymentException;
use App\Services\TelegramStarsPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(Request $request, TelegramStarsPaymentService $stars, ConsentService $consents): Response
    {
        return Inertia::render('Plans', [
            'billing' => $this->state($request, $stars, $consents),
        ]);
    }

    public function status(Request $request, TelegramStarsPaymentService $stars, ConsentService $consents): JsonResponse
    {
        return response()->json(['billing' => $this->state($request, $stars, $consents)]);
    }

    public function invoice(Request $request, TelegramStarsPaymentService $stars): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'in:basic,pro']]);
        try {
            $url = $stars->createSubscriptionInvoice($request->user(), $data['plan']);
        } catch (TelegramStarsPaymentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['invoice_url' => $url]);
    }

    public function cancel(Request $request, Subscription $subscription, TelegramStarsPaymentService $stars): RedirectResponse
    {
        return $this->setAutoRenew($request, $subscription, $stars, true);
    }

    public function resume(Request $request, Subscription $subscription, TelegramStarsPaymentService $stars): RedirectResponse
    {
        return $this->setAutoRenew($request, $subscription, $stars, false);
    }

    private function setAutoRenew(
        Request $request,
        Subscription $subscription,
        TelegramStarsPaymentService $stars,
        bool $cancel,
    ): RedirectResponse {
        try {
            $stars->setAutoRenew($request->user(), $subscription, $cancel);
        } catch (TelegramStarsPaymentException $exception) {
            return back()->withErrors(['billing' => $exception->getMessage()]);
        }

        return back()->with('status', $cancel ? 'Продление остановлено.' : 'Продление включено.');
    }

    /** @return array<string, mixed> */
    private function state(Request $request, TelegramStarsPaymentService $stars, ConsentService $consents): array
    {
        $user = $request->user();
        $configuration = $stars->configuration();
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->with('plan')->where('user_id', $user->id)
            ->where('source', SubscriptionSource::TelegramStars)
            ->latest('id')->first();
        $payments = Payment::query()->with('plan')->where('user_id', $user->id)
            ->where('provider', 'telegram_stars')->latest('id')->limit(20)->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'plan' => $payment->plan?->name,
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->toAtomString(),
                'period_ends_at' => $payment->period_ends_at?->toAtomString(),
            ])->all();

        return [
            ...$configuration,
            'consents_current' => $configuration['enabled'] && $consents->hasCurrentRequiredConsents($user),
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->id,
                'plan' => $subscription->plan?->name,
                'status' => $subscription->status->value,
                'ends_at' => $subscription->ends_at?->toAtomString(),
                'auto_renew' => $subscription->auto_renew,
                'renewal_failed' => $subscription->renewal_failed_at !== null,
            ],
            'payments' => $payments,
        ];
    }
}
