<?php

namespace App\Http\Controllers;

use App\Services\TenderCalendarService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class TenderCalendarController extends Controller
{
    public function index(Request $request, TenderCalendarService $calendar): Response|HttpResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/']]);
        $timezone = $calendar->timezone($user);
        $month = $data['month'] ?? now($timezone)->format('Y-m');
        $events = $calendar->events($user, $month);
        if ($request->routeIs('calendar.export')) {
            return response($calendar->ics($events), 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="tenders-'.$month.'.ics"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return Inertia::render('TenderCalendar', ['month' => $month, 'timezone' => $timezone, 'events' => $events]);
    }
}
