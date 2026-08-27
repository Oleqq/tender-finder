<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\AdminAccessAnalyticsService;
use App\Services\LocalMvpOperatorService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationsDashboardController extends Controller
{
    public function show(
        Request $request,
        AdminAccessAnalyticsService $analytics,
        LocalMvpOperatorService $mvpOperator,
    ): Response {
        $user = $request->user();

        abort_unless(
            $user?->role === UserRole::SuperAdmin
            && (! $mvpOperator->isTestOperatorIdentity($user) || $mvpOperator->isOperator($user)),
            403,
        );

        return Inertia::render('OperationsDemo', [
            'accessMetrics' => $analytics->snapshot(),
        ]);
    }
}
