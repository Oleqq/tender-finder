<?php

namespace App\Http\Controllers;

use App\Enums\TenderUserStatus;
use App\Models\Tender;
use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpTenderWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocalMvpTenderStateController extends Controller
{
    public function update(
        Request $request,
        Tender $tender,
        LocalMvpOperatorService $operator,
        LocalMvpTenderWorkspaceService $workspace,
    ): JsonResponse {
        abort_unless($operator->isOperator($request->user()), 404);
        abort_unless($workspace->canAccessTender($request->user(), $tender), 404);

        $attributes = $request->validate([
            'status' => ['required', Rule::enum(TenderUserStatus::class)],
        ]);

        return response()->json([
            'tender' => $workspace->updateStatus(
                $request->user(),
                $tender,
                TenderUserStatus::from($attributes['status']),
            ),
        ]);
    }

    public function bulkUpdate(
        Request $request,
        LocalMvpOperatorService $operator,
        LocalMvpTenderWorkspaceService $workspace,
    ): JsonResponse {
        abort_unless($operator->isOperator($request->user()), 404);

        $attributes = $request->validate([
            'tender_ids' => ['required', 'array', 'min:1', 'max:60'],
            'tender_ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['required', Rule::enum(TenderUserStatus::class)],
        ]);
        $tenderIds = array_map('intval', $attributes['tender_ids']);

        abort_unless($workspace->hasOnlyAccessibleTenders($request->user(), $tenderIds), 404);

        return response()->json([
            'tenders' => $workspace->updateStatuses(
                $request->user(),
                $tenderIds,
                TenderUserStatus::from($attributes['status']),
            ),
        ]);
    }
}
