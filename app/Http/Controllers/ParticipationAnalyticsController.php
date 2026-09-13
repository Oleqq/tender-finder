<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\ParticipationAnalyticsService;
use App\Services\TeamWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ParticipationAnalyticsController extends Controller
{
    public function index(Request $request, ParticipationAnalyticsService $analytics, TeamWorkspaceService $scope): Response
    {
        [$user, $team, $period] = $this->context($request, $scope);

        return Inertia::render('ParticipationAnalytics', [...$scope->props($user, $team), 'analytics' => $analytics->snapshot($user, $team, $period)]);
    }

    public function export(Request $request, ParticipationAnalyticsService $analytics, TeamWorkspaceService $scope): HttpResponse
    {
        [$user, $team, $period] = $this->context($request, $scope);

        return response($analytics->csv($user, $team, $period), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="participation-analytics.csv"',
        ]);
    }

    /** @return array{User, Team|null, string} */
    private function context(Request $request, TeamWorkspaceService $scope): array
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = $scope->context($request);
        $data = validator($request->query(), ['period' => ['nullable', Rule::in(['30', '90', '365', 'all'])]])->validate();

        return [$user, $team, $data['period'] ?? '90'];
    }
}
