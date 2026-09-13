<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ParticipationEconomicsController extends Controller
{
    public function update(Request $request, Tender $tender, TenderWorkService $work, TeamWorkspaceService $scope): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = $scope->context($request, true);
        $work->authorize($user, $tender, $team);
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2'];
        $data = $request->validate([
            'planned_revenue' => $money, 'planned_cost' => $money, 'security_cost' => $money,
            'commission_cost' => $money, 'other_cost' => $money, 'actual_revenue' => $money, 'actual_cost' => $money,
            'decision' => ['nullable', Rule::in(['go', 'no_go'])], 'decision_note' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $participation = DB::transaction(function () use ($user, $team, $tender, $work, $scope, $data): TenderParticipation {
            $scope->lock($user, $team);
            $participation = $work->participations($user, $team)->where('tender_id', $tender->id)->lockForUpdate()->firstOrFail();
            abort_if($participation->economics_version !== (int) $data['version'], 409, 'Экономика заявки изменена в другой вкладке. Обновите страницу.');
            $moneyFields = ['planned_revenue', 'planned_cost', 'security_cost', 'commission_cost', 'other_cost', 'actual_revenue', 'actual_cost'];
            $participation->fill([
                ...array_intersect_key($data, array_flip($moneyFields)),
                'participation_decision' => $data['decision'] ?? null,
                'decision_note' => isset($data['decision_note']) ? trim($data['decision_note']) : null,
                'economics_version' => $participation->economics_version + 1,
            ])->save();
            if ($team) {
                app(TeamActivityService::class)->record($team, $user, 'economics_updated', ['participation_id' => $participation->id,
                    'tender_id' => $tender->id, 'decision' => $participation->participation_decision]);
            }

            return $participation;
        });

        return response()->json(['economics' => $work->economics($participation)]);
    }
}
