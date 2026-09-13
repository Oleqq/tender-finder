<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Team;
use App\Models\Tender;
use App\Models\User;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChecklistTemplateController extends Controller
{
    /** @return Builder<ChecklistTemplate> */
    public function templates(User $user, ?Team $team): Builder
    {
        return ChecklistTemplate::query()->when($team, fn ($q) => $q->where('team_id', $team->id), fn ($q) => $q->whereNull('team_id')->where('user_id', $user->id));
    }

    public function store(Request $request, TeamWorkspaceService $scope): JsonResponse
    {
        $user = $request->user();
        $team = $scope->context($request, true);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'string', 'max:240']]);
        $template = DB::transaction(function () use ($user, $team, $scope, $data): ChecklistTemplate {
            $scope->lock($user, $team);
            abort_if($this->templates($user, $team)->count() >= 50, 422, 'Допускается до 50 шаблонов.');

            return ChecklistTemplate::query()->create([...$data, 'user_id' => $user->id, 'team_id' => $team?->id]);
        });

        return response()->json(['template' => $template], 201);
    }

    public function destroy(Request $request, int $template, TeamWorkspaceService $scope): JsonResponse
    {
        $user = $request->user();
        $team = $scope->context($request, true);
        DB::transaction(function () use ($user, $team, $scope, $template): void {
            $scope->lock($user, $team);
            $this->templates($user, $team)->findOrFail($template)->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function apply(Request $request, Tender $tender, int $template, TeamWorkspaceService $scope, TenderWorkService $work): JsonResponse
    {
        $user = $request->user();
        $team = $scope->context($request, true);
        $work->authorize($user, $tender, $team);
        $p = DB::transaction(function () use ($user, $team, $scope, $work, $tender, $template) {
            $scope->lock($user, $team);
            $p = $work->participations($user, $team)->where('tender_id', $tender->id)->lockForUpdate()->firstOrFail();
            $template = $this->templates($user, $team)->findOrFail($template);
            $applications = DB::table('checklist_template_applications')->where('participation_id', $p->id)->where('template_id', $template->id);
            if ($applications->exists()) {
                return $p;
            }
            abort_if($p->items()->count() + count($template->items) > 100, 422, 'Шаблон превысит лимит 100 задач.');
            foreach ($template->items as $title) {
                $p->items()->create(['title' => $title]);
            }
            DB::table('checklist_template_applications')->insert(['participation_id' => $p->id, 'template_id' => $template->id]);

            return $p;
        });

        return response()->json(['participation' => $work->present($p)]);
    }
}
