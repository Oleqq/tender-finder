<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpSearchSnapshotService;
use App\Services\LocalMvpTenderWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LocalMvpOperatorSessionController extends Controller
{
    public function store(
        Request $request,
        LocalMvpOperatorService $operator,
        LocalMvpSearchSnapshotService $snapshots,
        LocalMvpTenderWorkspaceService $workspace,
    ): Response {
        abort_unless($operator->isEnabled(), 404);

        $user = $operator->provision();

        if ($request->user()?->id !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        $snapshot = $snapshots->currentFor($user);

        return Inertia::render('MvpWorkspace', [
            'currentTenders' => $workspace->tendersForIds(
                $user,
                $snapshot === null ? [] : $snapshot->tender_ids,
            ),
            'currentSearch' => $snapshot === null ? null : [
                'query' => $snapshot->query,
                'itemsSeen' => $snapshot->items_seen,
                'itemsMatched' => $snapshot->items_matched,
                'itemsCreated' => $snapshot->items_created,
                'pagesRequested' => $snapshot->pages_requested,
                'pagesLoaded' => $snapshot->pages_loaded,
                'partiallyLoaded' => $snapshot->partially_loaded,
            ],
            'historyTenders' => $workspace->historyTendersFor(
                $user,
                $snapshots->historyTenderIdsFor($user),
            ),
            'savedSearches' => $user->searchQueries()
                ->where('status', '!=', 'deleted')
                ->latest()
                ->get(['id', 'name', 'keywords', 'filters'])
                ->map(fn ($query): array => [
                    'id' => $query->id,
                    'name' => $query->name,
                    'keywords' => $query->keywords,
                    'filters' => $query->filters,
                ])
                ->values(),
        ]);
    }
}
