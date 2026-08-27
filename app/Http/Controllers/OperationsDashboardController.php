<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\AdminAccessAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationsDashboardController extends Controller
{
    public function show(Request $request, AdminAccessAnalyticsService $analytics): Response
    {
        abort_unless($request->user()?->role === UserRole::SuperAdmin, 403);

        return Inertia::render('OperationsDemo', [
            'accessMetrics' => $analytics->snapshot(),
        ]);
    }
}
