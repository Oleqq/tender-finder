<?php

namespace App\Http\Controllers;

use App\Enums\QueryStatus;
use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;
use App\Services\LocalMvpSearchSnapshotService;
use App\Services\LocalMvpTenderWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SavedSearchRunHistoryController extends Controller
{
    public function index(
        Request $request,
        SearchQuery $query,
        LocalMvpSearchSnapshotService $snapshots,
    ): JsonResponse {
        $this->assertAccess($request, $query);

        $runs = $query->manualRuns()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (LocalMvpSearchSnapshot $run): array => $this->runDto(
                $run,
                count($snapshots->newTenderIdsFor($run)),
            ))
            ->values();

        return response()->json(['runs' => $runs]);
    }

    public function show(
        Request $request,
        SearchQuery $query,
        LocalMvpSearchSnapshot $run,
        LocalMvpSearchSnapshotService $snapshots,
        LocalMvpTenderWorkspaceService $workspace,
    ): JsonResponse {
        $this->assertAccess($request, $query);
        abort_unless(
            $run->user_id === $request->user()?->id && $run->search_query_id === $query->id,
            404,
        );

        $onlyNew = $request->boolean('only_new');
        $newTenderIds = $snapshots->newTenderIdsFor($run);
        $tenderIds = $onlyNew ? $newTenderIds : $run->tender_ids;

        return response()->json([
            'run' => $this->runDto($run, count($newTenderIds)),
            'only_new' => $onlyNew,
            'tenders' => $workspace->tendersForIds(
                $request->user(),
                $tenderIds,
                $snapshots->matchReasonsFor($run),
            ),
        ]);
    }

    private function assertAccess(
        Request $request,
        SearchQuery $query,
    ): void {
        abort_unless(
            $query->user_id === $request->user()?->id && $query->status !== QueryStatus::Deleted,
            404,
        );
    }

    /** @return array<string, mixed> */
    private function runDto(LocalMvpSearchSnapshot $run, int $newCount): array
    {
        return [
            'id' => $run->id,
            'created_at' => $run->created_at?->toAtomString(),
            'items_seen' => $run->items_seen,
            'items_matched' => $run->items_matched,
            'items_created' => $run->items_created,
            'new_count' => $newCount,
            'pages_requested' => $run->pages_requested,
            'pages_loaded' => $run->pages_loaded,
            'partially_loaded' => $run->partially_loaded,
        ];
    }
}
