<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Entitlement;
use App\Models\SubscriptionOrder;
use App\Models\User;
use App\Services\AccessFreezeService;
use App\Services\AccessService;
use App\Services\DemoCheckoutGate;
use App\Services\SubscriptionCatalog;
use App\Services\SubscriptionOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DemoCheckoutController extends Controller
{
    public function reviewer(Request $request, DemoCheckoutGate $gate): RedirectResponse
    {
        abort_unless($gate->enabled(), 404);
        abort_unless((int) $request->query('expires') > now()->timestamp, 403);
        $request->session()->put('demo_reviewer_until', (int) $request->query('expires'));
        $request->session()->regenerate();

        return redirect('/demo')->withHeaders(['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
    }

    public function index(Request $request, DemoCheckoutGate $gate, SubscriptionCatalog $catalog): Response
    {
        $gate->authorizeEntry($request);
        $user = $request->user();
        abort_if($user !== null && ! $user->is_demo_guest, 403, 'Откройте демонстрацию в отдельном профиле браузера.');
        $order = $user ? SubscriptionOrder::query()->where('user_id', $user->id)->orderByDesc(DB::table('subscription_order_events')->selectRaw('MAX(id)')->whereColumn('order_id', 'subscription_orders.id'))->first() : null;
        if ($order?->status === 'succeeded' && ! app(AccessService::class)->hasActiveAccess($user)) {
            $order = null;
        }

        return Inertia::render('DemoCheckout', [
            'offers' => $catalog->all(), 'order' => $order,
            'previewEnds' => collect([1, 3, 12])->mapWithKeys(fn (int $months) => [$months => $catalog->endAt(now(), $months)->toAtomString()]),
        ]);
    }

    public function session(Request $request, DemoCheckoutGate $gate): RedirectResponse
    {
        $gate->authorizeEntry($request);
        abort_if($request->user() !== null, 409);
        $guest = new User;
        $guest->forceFill([
            'name' => 'Демо-гость', 'role' => UserRole::Subscriber,
            'is_browser_guest' => true, 'is_demo_guest' => true, 'last_seen_at' => now(),
        ])->save();
        Auth::login($guest, true);
        $request->session()->regenerate();

        return redirect('/consents');
    }

    public function create(Request $request, DemoCheckoutGate $gate, SubscriptionOrderService $orders): JsonResponse
    {
        $user = $gate->authorize($request);
        $this->onlyFields($request, ['plan_code', 'duration_months', 'idempotency_key']);
        $data = $request->validate([
            'plan_code' => ['required', Rule::in(['basic', 'pro', 'ultra'])],
            'duration_months' => ['required', 'integer', Rule::in([1, 3, 12])],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        return response()->json(['order' => $orders->create($user, $data['plan_code'], (int) $data['duration_months'], $data['idempotency_key'])]);
    }

    public function confirm(Request $request, string $order, DemoCheckoutGate $gate, SubscriptionOrderService $orders): JsonResponse
    {
        $user = $gate->authorize($request);
        $this->onlyFields($request, ['outcome']);
        $data = $request->validate(['outcome' => ['required', Rule::in(['succeeded', 'canceled', 'failed', 'pending'])]]);
        $result = $orders->confirm($user, $order, $data['outcome']);

        return response()->json(['order' => $result, 'access' => app(AccessService::class)->snapshotFor($user)->toArray()]);
    }

    public function show(Request $request, string $order, DemoCheckoutGate $gate): JsonResponse
    {
        $user = $gate->authorize($request);

        return response()->json([
            'order' => SubscriptionOrder::query()->where('user_id', $user->id)->findOrFail($order),
            'access' => app(AccessService::class)->snapshotFor($user)->toArray(),
        ]);
    }

    public function expire(Request $request, DemoCheckoutGate $gate): JsonResponse
    {
        $user = $gate->authorize($request);
        $this->onlyFields($request, []);
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Entitlement::query()->where('user_id', $user->id)->where('status', SubscriptionStatus::Active)
                ->whereHas('subscription', fn ($query) => $query->whereIn('source', [SubscriptionSource::Trial, SubscriptionSource::Demo]))
                ->get()->each(function (Entitlement $entitlement): void {
                    $entitlement->update(['ends_at' => now(), 'status' => SubscriptionStatus::Expired]);
                    $entitlement->subscription?->update(['ends_at' => now(), 'status' => SubscriptionStatus::Expired]);
                });
            app(AccessFreezeService::class)->freezeIfInactive($user, now());
            // A QA time jump finishes only this guest's demo access, never a real account.
            foreach (SubscriptionOrder::query()->where('user_id', $user->id)->where('status', 'succeeded')->get() as $order) {
                DB::table('subscription_order_events')->insert(['order_id' => $order->id, 'from_status' => 'succeeded', 'to_status' => 'demo_period_ended', 'created_at' => now()]);
            }
        });

        return response()->json(['access' => app(AccessService::class)->snapshotFor($user)->toArray()]);
    }

    /** @param list<string> $fields */
    private function onlyFields(Request $request, array $fields): void
    {
        abort_if(array_diff(array_keys($request->all()), [...$fields, '_token']) !== [], 422, 'Запрос содержит неподдерживаемые поля.');
    }
}
