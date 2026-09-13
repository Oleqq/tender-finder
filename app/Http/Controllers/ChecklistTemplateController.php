<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Team;
use App\Models\Tender;
use App\Models\User;
use App\Services\TeamActivityService;
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

    public function store(Request $request, TeamWorkspaceService $scope, TeamActivityService $activity): JsonResponse
    {
        $user = $request->user();
        $team = $scope->context($request, true);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*' => ['required', 'string', 'max:240']]);
        $template = DB::transaction(function () use ($user, $team, $scope, $activity, $data): ChecklistTemplate {
            $scope->lock($user, $team);
            abort_if($this->templates($user, $team)->count() >= 50, 422, 'Допускается до 50 шаблонов.');

            $template = ChecklistTemplate::query()->create([...$data, 'user_id' => $user->id, 'team_id' => $team?->id, 'version' => 1]);
            $this->snapshot($template, $user);
            if ($team) {
                $activity->record($team, $user, 'template_created', ['template_id' => $template->id, 'template_name' => $template->name, 'version' => 1]);
            }

            return $template;
        });

        return response()->json(['template' => $template->load('versions')], 201);
    }

    public function update(Request $request, int $template, TeamWorkspaceService $scope, TeamActivityService $activity): JsonResponse
    {
        $user = $request->user();
        $team = $scope->context($request, true);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'string', 'max:240'], 'version' => ['required', 'integer', 'min:1']]);
        $template = DB::transaction(function () use ($user, $team, $scope, $activity, $template, $data): ChecklistTemplate {
            $scope->lock($user, $team);
            $template = $this->templates($user, $team)->whereKey($template)->lockForUpdate()->firstOrFail();
            abort_if($template->version !== (int) $data['version'], 409, 'Шаблон изменён в другой вкладке. Обновите страницу.');
            $name = trim($data['name']);
            $items = array_values(array_map('trim', $data['items']));
            if ($template->name === $name && $template->items === $items) {
                return $template;
            }
            $template->update(['name' => $name, 'items' => $items, 'version' => $template->version + 1]);
            $this->snapshot($template, $user);
            if ($team) {
                $activity->record($team, $user, 'template_updated', ['template_id' => $template->id, 'template_name' => $template->name, 'version' => $template->version]);
            }

            return $template;
        });

        return response()->json(['template' => $template->load('versions')]);
    }

    public function destroy(Request $request, int $template, TeamWorkspaceService $scope, TeamActivityService $activity): JsonResponse
    {
        $user = $request->user();
        $team = $scope->context($request, true);
        DB::transaction(function () use ($user, $team, $scope, $activity, $template): void {
            $scope->lock($user, $team);
            $template = $this->templates($user, $team)->findOrFail($template);
            if ($team) {
                $activity->record($team, $user, 'template_deleted', ['template_id' => $template->id, 'template_name' => $template->name, 'version' => $template->version]);
            }
            $template->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function apply(Request $request, Tender $tender, int $template, TeamWorkspaceService $scope, TenderWorkService $work, TeamActivityService $activity): JsonResponse
    {
        $user = $request->user();
        $team = $scope->context($request, true);
        $work->authorize($user, $tender, $team);
        $p = DB::transaction(function () use ($user, $team, $scope, $work, $activity, $tender, $template) {
            $scope->lock($user, $team);
            $p = $work->participations($user, $team)->where('tender_id', $tender->id)->lockForUpdate()->firstOrFail();
            $template = $this->templates($user, $team)->findOrFail($template);
            $applications = DB::table('checklist_template_applications')->where('participation_id', $p->id)->where('template_id', $template->id)
                ->where('template_version', $template->version);
            if ($applications->exists()) {
                return $p;
            }
            abort_if($p->items()->count() + count($template->items) > 100, 422, 'Шаблон превысит лимит 100 задач.');
            foreach ($template->items as $title) {
                $p->items()->create(['title' => $title]);
            }
            DB::table('checklist_template_applications')->insert(['participation_id' => $p->id, 'template_id' => $template->id,
                'template_version' => $template->version]);
            if ($team) {
                $activity->record($team, $user, 'template_applied', ['template_id' => $template->id, 'template_name' => $template->name,
                    'version' => $template->version, 'participation_id' => $p->id]);
            }

            return $p;
        });

        return response()->json(['participation' => $work->present($p)]);
    }

    private function snapshot(ChecklistTemplate $template, User $actor): void
    {
        DB::table('checklist_template_versions')->insert([
            'template_id' => $template->id,
            'version' => $template->version,
            'name' => $template->name,
            'items' => json_encode($template->items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
