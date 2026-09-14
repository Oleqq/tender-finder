<?php

namespace App\Http\Controllers;

use App\Models\ParticipationApprovalRequest;
use App\Models\Tender;
use App\Services\ParticipationApprovalService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ParticipationApprovalController extends Controller
{
    public function store(Request $request, Tender $tender, TenderWorkService $work, ParticipationApprovalService $approvals): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = app(TeamWorkspaceService::class)->context($request, true);
        abort_unless($team !== null, 404);
        $work->authorize($user, $tender, $team);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $participation = $work->participations($user, $team)->where('tender_id', $tender->id)->firstOrFail();
        $approval = $approvals->request($user, $team, $participation, $data['note'] ?? null);

        return response()->json(['approval' => $approvals->present($participation)], $approval->wasRecentlyCreated ? 201 : 200);
    }

    public function vote(Request $request, Tender $tender, ParticipationApprovalRequest $approval, ParticipationApprovalService $approvals): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = app(TeamWorkspaceService::class)->context($request, true);
        abort_unless($team !== null, 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['nullable', 'string', 'max:2000'], 'version' => ['required', 'integer', 'min:1']]);
        abort_unless($approval->participation?->tender_id === $tender->id, 404);
        $approvals->vote($user, $team, $approval, $data['decision'], $data['comment'] ?? null, (int) $data['version']);

        return response()->json(['approval' => $approvals->present($approval->participation->fresh())]);
    }
}
