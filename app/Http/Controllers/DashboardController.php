<?php

namespace App\Http\Controllers;

use App\Models\TenderUserState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $states = TenderUserState::query()
            ->where('user_id', $user->id)
            ->whereNotNull('next_action_on')
            ->with('tender')
            ->orderBy('next_action_on')
            ->limit(6)
            ->get();
        $today = today();

        return Inertia::render('Dashboard', [
            'nextActions' => [
                'overdue_count' => TenderUserState::query()
                    ->where('user_id', $user->id)
                    ->whereDate('next_action_on', '<', $today)
                    ->count(),
                'today_count' => TenderUserState::query()
                    ->where('user_id', $user->id)
                    ->whereDate('next_action_on', $today)
                    ->count(),
                'items' => $states->map(fn (TenderUserState $state): array => [
                    'tender_id' => $state->tender_id,
                    'title' => $state->tender->title,
                    'reg_number' => $state->tender->reg_number,
                    'next_action_on' => $state->next_action_on?->format('Y-m-d'),
                    'status' => $state->status->value,
                    'tags' => $state->tags ?? [],
                ])->values(),
            ],
        ]);
    }
}
