<?php

namespace App\Http\Controllers;

use App\Services\AdminAccessAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationsDashboardController extends Controller
{
    public function show(
        Request $request,
        AdminAccessAnalyticsService $analytics,
    ): Response {
        $period = $request->query('period');

        return Inertia::render('OperationsDashboard', [
            'dashboard' => $analytics->snapshot(is_string($period) ? $period : null),
        ]);
    }
}
