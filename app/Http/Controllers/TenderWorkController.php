<?php

namespace App\Http\Controllers;

use App\Enums\ParticipationStage;
use App\Models\Tender;
use App\Models\TenderChecklistItem;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\ParticipationCommentService;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TenderWorkController extends Controller
{
    public function index(Request $request, TenderWorkService $work): Response
    {
        $user = $this->user($request);
        $scope = app(TeamWorkspaceService::class);
        $team = $scope->context($request, ! $request->isMethod('get'));
        $filters = $request->validate(['stage' => ['nullable', Rule::enum(ParticipationStage::class)]]);
        $stage = $filters['stage'] ?? null;
        $base = $work->participations($user, $team)
            ->when(! $team, fn ($q) => $q->whereIn('tender_id', $work->accessible($user)->select('tenders.id')));
        $counts = (clone $base)->selectRaw('stage, COUNT(*) as total')->groupBy('stage')->pluck('total', 'stage');
        $rows = (clone $base)->when($stage, fn ($q) => $q->where('stage', $stage))
            ->with('tender')->withCount(['items', 'items as completed_count' => fn ($q) => $q->whereNotNull('completed_at')])
            ->latest('updated_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $comments = app(ParticipationCommentService::class);
        $rows->through(fn (TenderParticipation $p): array => [
            'id' => $p->id, 'tender_id' => $p->tender_id, 'title' => $p->tender->title,
            'assignee_id' => $p->assignee_id, 'stage' => $p->stage->value, 'loss_reason' => $p->loss_reason,
            'deadline_at' => $p->tender->deadline_at?->toAtomString(),
            'items_count' => (int) $p->getAttribute('items_count'), 'completed_count' => (int) $p->getAttribute('completed_count'),
            'unread_comments' => $comments->unreadCount($p, $user),
            'decision' => $p->participation_decision,
        ]);

        return Inertia::render('Participation', [...$scope->props($user, $team), 'participations' => $rows, 'stage' => $stage, 'counts' => $counts]);
    }

    public function show(Request $request, Tender $tender, TenderWorkService $work): Response
    {
        $user = $this->user($request);
        $scope = app(TeamWorkspaceService::class);
        $team = $scope->context($request, ! $request->isMethod('get'));
        $work->authorize($user, $tender, $team);

        $participation = $work->participations($user, $team)->where('tender_id', $tender->id)->first();
        $comments = $participation ? app(ParticipationCommentService::class)->present($participation, $user, $team) : [];
        if ($participation) {
            $lastCommentId = $comments === [] ? 0 : max(array_column($comments, 'id'));
            app(ParticipationCommentService::class)->markRead($participation, $user, $lastCommentId);
        }

        return Inertia::render('TenderWork', [
            ...$scope->props($user, $team),
            'templates' => app(ChecklistTemplateController::class)->templates($user, $team)->with('versions')->orderBy('name')->get(['id', 'name', 'items', 'version']),
            'tender' => ['id' => $tender->id, 'title' => $tender->title, 'canonical_url' => $tender->canonical_url,
                'deadline_at' => $tender->deadline_at?->toAtomString()],
            'participation' => $work->present($participation),
            'comments' => $comments,
        ]);
    }

    public function update(Request $request, Tender $tender, TenderWorkService $work): JsonResponse
    {
        $user = $this->user($request);
        $scope = app(TeamWorkspaceService::class);
        $team = $scope->context($request, ! $request->isMethod('get'));
        $work->authorize($user, $tender, $team);
        $data = $request->validate([
            'stage' => ['required', Rule::enum(ParticipationStage::class)],
            'loss_reason' => ['required_if:stage,lost', 'nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:0'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $participation = DB::transaction(function () use ($user, $tender, $data, $work, $team, $scope): TenderParticipation {
            // Serializes creation as well as updates for this user's private workflow.
            $scope->lock($user, $team);
            $p = $work->participations($user, $team)->where('tender_id', $tender->id)->lockForUpdate()->first();
            $version = $p === null ? 0 : $p->version;
            abort_if((int) $data['version'] !== $version, 409, 'Данные изменились. Обновите страницу.');
            $old = $p?->stage->value;
            $assignee = array_key_exists('assignee_id', $data) ? $scope->assignee($user, $team, $data['assignee_id']) : $p?->assignee_id;
            $reason = $data['stage'] === 'lost' ? trim($data['loss_reason']) : null;
            if ($p !== null && $old === $data['stage'] && $p->loss_reason === $reason && $p->assignee_id === $assignee) {
                return $p;
            }
            $p ??= new TenderParticipation(['user_id' => $user->id, 'team_id' => $team?->id, 'tender_id' => $tender->id, 'version' => 0]);
            $p->fill(['assignee_id' => $assignee, 'stage' => $data['stage'], 'loss_reason' => $reason, 'version' => $p->version + 1])->save();
            DB::table('tender_participation_events')->insert(['participation_id' => $p->id, 'from_stage' => $old,
                'actor_id' => $user->id, 'to_stage' => $p->stage->value, 'reason' => $reason, 'created_at' => now()]);
            if ($team) {
                app(TeamActivityService::class)->record($team, $user, 'participation_updated', ['participation_id' => $p->id,
                    'tender_id' => $tender->id, 'stage' => $p->stage->value, 'assignee_id' => $assignee]);
            }

            return $p;
        });

        return response()->json(['participation' => $work->present($participation)]);
    }

    public function storeItem(Request $request, Tender $tender, TenderWorkService $work): JsonResponse
    {
        $user = $this->user($request);
        $scope = app(TeamWorkspaceService::class);
        $team = $scope->context($request, ! $request->isMethod('get'));
        $work->authorize($user, $tender, $team);
        $data = $request->validate(['title' => ['required', 'string', 'max:240'], 'due_on' => ['required_if:reminder_enabled,true', 'nullable', 'date_format:Y-m-d'],
            'assignee_id' => ['nullable', 'integer'], 'reminder_enabled' => ['sometimes', 'boolean']]);
        $p = DB::transaction(function () use ($user, $tender, $data, $work, $team, $scope): TenderParticipation {
            $scope->lock($user, $team);
            $p = $work->participations($user, $team)->where('tender_id', $tender->id)->lockForUpdate()->firstOrFail();
            abort_if($p->items()->count() >= 100, 422, 'В чек-листе допускается до 100 задач.');
            $item = $p->items()->create(['assignee_id' => $scope->assignee($user, $team, $data['assignee_id'] ?? null), 'reminder_enabled' => $data['reminder_enabled'] ?? false, 'title' => trim($data['title']), 'due_on' => $data['due_on'] ?? null]);
            if ($team) {
                app(TeamActivityService::class)->record($team, $user, 'task_created', ['participation_id' => $p->id, 'task_id' => $item->id]);
            }

            return $p;
        });

        return response()->json(['participation' => $work->present($p)], 201);
    }

    public function updateItem(Request $request, Tender $tender, TenderChecklistItem $item, TenderWorkService $work): JsonResponse
    {
        return $this->mutateItem($request, $tender, $item, $work, false);
    }

    public function destroyItem(Request $request, Tender $tender, TenderChecklistItem $item, TenderWorkService $work): JsonResponse
    {
        return $this->mutateItem($request, $tender, $item, $work, true);
    }

    private function mutateItem(Request $request, Tender $tender, TenderChecklistItem $item, TenderWorkService $work, bool $delete): JsonResponse
    {
        $user = $this->user($request);
        $scope = app(TeamWorkspaceService::class);
        $team = $scope->context($request, ! $request->isMethod('get'));
        $work->authorize($user, $tender, $team);
        abort_unless($work->participations($user, $team)->whereKey($item->participation_id)->where('tender_id', $tender->id)->exists(), 404);
        $data = $request->validate($delete ? ['version' => ['required', 'integer', 'min:1']] : [
            'assignee_id' => ['sometimes', 'nullable', 'integer'], 'reminder_enabled' => ['sometimes', 'boolean'],
            'title' => ['required', 'string', 'max:240'], 'due_on' => ['required_if:reminder_enabled,true', 'nullable', 'date_format:Y-m-d'],
            'completed' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($item, $data, $delete, $user, $scope, $team): void {
            $scope->lock($user, $team);
            $locked = TenderChecklistItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->version !== (int) $data['version'], 409, 'Задача изменена в другой вкладке. Обновите страницу.');
            if ($delete) {
                $locked->delete();
                if ($team) {
                    app(TeamActivityService::class)->record($team, $user, 'task_deleted', ['participation_id' => $locked->participation_id, 'task_id' => $locked->id]);
                }
            } else {
                abort_if(($data['reminder_enabled'] ?? $locked->reminder_enabled) && empty($data['due_on']), 422, 'Для напоминания нужен срок задачи.');
                $locked->fill(['assignee_id' => array_key_exists('assignee_id', $data) ? $scope->assignee($user, $team, $data['assignee_id']) : $locked->assignee_id,
                    'reminder_enabled' => $data['reminder_enabled'] ?? $locked->reminder_enabled, 'title' => trim($data['title']), 'due_on' => $data['due_on'] ?? null,
                    'completed_at' => $data['completed'] ? ($locked->completed_at ?? now()) : null,
                    'version' => $locked->version + 1])->save();
                if ($team) {
                    app(TeamActivityService::class)->record($team, $user, 'task_updated', ['participation_id' => $locked->participation_id, 'task_id' => $locked->id]);
                }
            }
        });

        return response()->json(['participation' => $work->present($item->participation->refresh())]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }
}
