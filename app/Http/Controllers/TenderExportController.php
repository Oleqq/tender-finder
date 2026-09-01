<?php

namespace App\Http\Controllers;

use App\Enums\QueryStatus;
use App\Models\LocalMvpSearchSnapshot;
use App\Models\SearchQuery;
use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpSearchSnapshotService;
use App\Services\LocalMvpTenderWorkspaceService;
use App\Services\TenderExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TenderExportController extends Controller
{
    public function __invoke(
        Request $request,
        LocalMvpOperatorService $operator,
        LocalMvpSearchSnapshotService $snapshots,
        LocalMvpTenderWorkspaceService $workspace,
        TenderExportService $export,
    ): Response {
        abort_unless($operator->canUseWorkspace($request->user()), 404);

        $attributes = $request->validate([
            'format' => ['required', 'string', 'in:csv,xlsx'],
            'scope' => ['required', 'string', 'in:current,selected,run_new'],
            'tender_ids' => ['nullable', 'required_unless:scope,run_new', 'array', 'min:1', 'max:500'],
            'tender_ids.*' => ['required', 'integer', 'distinct'],
            'query_id' => ['nullable', 'required_if:scope,run_new', 'integer'],
            'run_id' => ['nullable', 'required_if:scope,run_new', 'integer'],
            'filter_summary' => ['nullable', 'string', 'max:500'],
        ]);
        $tenderIds = $attributes['scope'] === 'run_new'
            ? $this->newTenderIds($request, $attributes, $snapshots)
            : array_map('intval', $attributes['tender_ids'] ?? []);

        if ($tenderIds !== []) {
            abort_unless($workspace->hasOnlyAccessibleTenders($request->user(), $tenderIds), 404);
        }

        $tenders = $workspace->tendersForIds($request->user(), $tenderIds);
        $format = $attributes['format'];
        $contents = $format === 'xlsx'
            ? $export->xlsx($tenders, $attributes['filter_summary'] ?? null)
            : $export->csv($tenders, $attributes['filter_summary'] ?? null);
        $contentType = $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';
        $filename = 'tender-finder-'.now()->format('Ymd-His').'.'.$format;

        return response($contents, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<int>
     */
    private function newTenderIds(
        Request $request,
        array $attributes,
        LocalMvpSearchSnapshotService $snapshots,
    ): array {
        $query = SearchQuery::query()->findOrFail((int) $attributes['query_id']);
        $run = LocalMvpSearchSnapshot::query()->findOrFail((int) $attributes['run_id']);
        abort_unless(
            $query->user_id === $request->user()->id
            && $query->status !== QueryStatus::Deleted
            && $run->user_id === $request->user()->id
            && $run->search_query_id === $query->id,
            404,
        );

        return $snapshots->newTenderIdsFor($run);
    }
}
