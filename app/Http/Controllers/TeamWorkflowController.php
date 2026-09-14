<?php

namespace App\Http\Controllers;

use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\TeamTenderRoutingRule;
use App\Services\TeamActivityService;
use App\Services\TeamWorkflowService;
use App\Services\TeamWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TeamWorkflowController extends Controller
{
    public function update(Request $request, Team $team, TeamWorkflowService $workflow): JsonResponse
    {
        app(TeamWorkspaceService::class)->authorize($request->user(), $team, true, true);
        $settings = $workflow->settings($team);
        $data = $request->validate([
            'review_sla_hours' => ['required', 'integer', Rule::in([1, 2, 4, 8, 12, 24, 48, 72, 168])],
            'assignment_mode' => ['required', Rule::in(['manual', 'round_robin', 'least_loaded'])],
            'notify_assignments' => ['required', 'boolean'], 'notify_sla' => ['required', 'boolean'],
            'digest_enabled' => ['required', 'boolean'], 'digest_time' => ['required', 'date_format:H:i'],
            'approval_enabled' => ['required', 'boolean'],
            'approval_min_revenue' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99'],
            'approval_max_margin_percent' => ['nullable', 'numeric', 'min:-999.99', 'max:999.99'],
            'required_approvals' => ['required', 'integer', 'min:1', 'max:10'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        abort_if($settings->version !== (int) $data['version'], 409, 'Настройки команды изменены. Обновите страницу.');
        $editors = app(TeamWorkspaceService::class)->editorCount($team);
        abort_if((int) $data['required_approvals'] > $editors, 422, 'Число согласующих не может превышать число редакторов команды.');
        $settings->fill([...$data, 'updated_by_id' => $request->user()->id, 'version' => $settings->version + 1])->save();
        app(TeamActivityService::class)->record($team, $request->user(), 'workflow_settings_updated', ['version' => $settings->version]);
        $workflow->sync($team);

        return response()->json(['settings' => $settings->fresh()]);
    }

    public function storeRule(Request $request, Team $team): JsonResponse
    {
        app(TeamWorkspaceService::class)->authorize($request->user(), $team, true, true);
        abort_if(TeamTenderRoutingRule::query()->where('team_id', $team->id)->count() >= 50, 422, 'Допускается до 50 правил маршрутизации.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'source' => ['nullable', Rule::in(['eis_rss', 'rostender'])], 'search_query_id' => ['nullable', 'integer'],
            'region' => ['nullable', 'string', 'max:160'], 'min_budget' => ['nullable', 'numeric', 'min:0'],
            'assignee_id' => ['required', 'integer'], 'enabled' => ['required', 'boolean'],
        ]);
        app(TeamWorkspaceService::class)->assignee($request->user(), $team, (int) $data['assignee_id']);
        if (! empty($data['search_query_id'])) {
            abort_unless(SearchQuery::query()->whereKey($data['search_query_id'])->whereIn('id', DB::table('team_search_queries')
                ->where('team_id', $team->id)->select('search_query_id'))->exists(), 422);
        }
        $rule = TeamTenderRoutingRule::query()->create(['team_id' => $team->id, ...$data]);
        app(TeamActivityService::class)->record($team, $request->user(), 'routing_rule_created', ['rule_id' => $rule->id]);

        return response()->json(['rule' => $rule], 201);
    }

    public function destroyRule(Request $request, Team $team, TeamTenderRoutingRule $rule): JsonResponse
    {
        app(TeamWorkspaceService::class)->authorize($request->user(), $team, true, true);
        abort_unless($rule->team_id === $team->id, 404);
        $rule->delete();
        app(TeamActivityService::class)->record($team, $request->user(), 'routing_rule_deleted', ['rule_id' => $rule->id]);

        return response()->json([], 204);
    }
}
