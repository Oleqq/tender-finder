<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionRenewal;
use App\Services\SubscriptionRenewalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class SubscriptionRenewalController extends Controller
{
    public function index(Request $request, SubscriptionRenewalService $renewals): InertiaResponse
    {
        return Inertia::render('SubscriptionRenewals', [
            'renewals' => SubscriptionRenewal::query()->where('user_id', $request->user()->id)->latest()->limit(100)->get()
                ->map(fn (SubscriptionRenewal $item) => $renewals->present($item))->all(),
        ]);
    }

    public function cancel(Request $request, string $renewal, SubscriptionRenewalService $renewals): JsonResponse
    {
        $item = SubscriptionRenewal::query()->where('user_id', $request->user()->id)->findOrFail($renewal);

        return response()->json(['renewal' => $renewals->present($renewals->cancel($item))]);
    }

    public function cancelLink(Request $request, string $renewal, SubscriptionRenewalService $renewals): Response
    {
        $item = SubscriptionRenewal::query()->findOrFail($renewal);
        if ($request->isMethod('POST')) {
            $item = $renewals->cancel($item);
        }

        return response()->view('renewal-cancel', ['renewal' => $renewals->present($item)])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
