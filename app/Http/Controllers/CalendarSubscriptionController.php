<?php

namespace App\Http\Controllers;

use App\Services\CalendarSubscriptionService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CalendarSubscriptionController extends Controller
{
    public function store(Request $request, CalendarSubscriptionService $subscriptions): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = app(TeamWorkspaceService::class)->context($request);
        $subscriptions->rotate($user, $team);

        return response()->json(['subscription' => $subscriptions->present($user, $team)], 201);
    }

    public function destroy(Request $request, CalendarSubscriptionService $subscriptions): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = app(TeamWorkspaceService::class)->context($request);
        $subscriptions->revoke($user, $team);

        return response()->json(['subscription' => $subscriptions->present($user, $team)]);
    }

    public function feed(string $token, CalendarSubscriptionService $subscriptions, TenderCalendarService $calendar): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);
        $subscription = $subscriptions->resolve($token);
        $name = $subscription->team === null ? 'TenderFinder — личные сроки' : 'TenderFinder — '.$subscription->team->name;

        return response($calendar->ics($calendar->subscriptionEvents($subscription->user, $subscription->team), $name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="tenderfinder.ics"',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
