<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class MvpOperatorWorkspaceResponseService
{
    public function __construct(
        private readonly LocalMvpOperatorService $operator,
        private readonly LocalMvpSearchSnapshotService $snapshots,
        private readonly LocalMvpTenderWorkspaceService $workspace,
        private readonly SearchQueryPresenter $queryPresenter,
    ) {}

    public function open(Request $request): Response
    {
        $user = $this->operator->provision();

        if ($request->user()?->id !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return $this->renderFor($user);
    }

    public function renderFor(User $user): Response
    {
        $snapshot = $this->snapshots->currentFor($user);

        return Inertia::render('MvpWorkspace', [
            'currentTenders' => $this->workspace->tendersForIds(
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
            'historyTenders' => $this->workspace->historyTendersFor(
                $user,
                $this->snapshots->historyTenderIdsFor($user),
            ),
            'savedSearches' => $user->searchQueries()
                ->where('status', '!=', 'deleted')
                ->with('latestManualRun')
                ->latest()
                ->get()
                ->map(fn ($query): array => $this->queryPresenter->toArray($query))
                ->values(),
        ]);
    }
}
