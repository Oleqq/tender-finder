<?php

namespace App\Services;

use App\Enums\ParticipationStage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TeamWorkspaceService
{
    public function context(Request $request, bool $write = false): ?Team
    {
        $data = validator($request->query(), ['team_id' => ['nullable', 'integer', 'min:1']])->validate();
        if (empty($data['team_id'])) {
            return null;
        }
        $team = Team::query()->findOrFail($data['team_id']);
        $this->authorize($request->user(), $team, $write);

        return $team;
    }

    public function role(User $user, Team $team): ?string
    {
        return DB::table('team_members')->where('team_id', $team->id)->where('user_id', $user->id)->value('role');
    }

    public function editorCount(Team $team): int
    {
        return DB::table('team_members')->where('team_id', $team->id)->whereIn('role', ['owner', 'member'])->count();
    }

    public function authorize(User $user, Team $team, bool $write = false, bool $owner = false): void
    {
        $role = $this->role($user, $team);
        abort_unless($role !== null, 404);
        abort_if($write && $team->archived_at !== null, 409, 'Команда находится в архиве. Восстановите её, чтобы вносить изменения.');
        abort_if(($write && $role === 'viewer') || ($owner && $team->owner_id !== $user->id), 403);
    }

    public function lock(User $user, ?Team $team): void
    {
        if ($team) {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $team, true);
        } else {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        }
    }

    public function assignee(User $user, ?Team $team, ?int $id): ?int
    {
        if ($id === null) {
            return null;
        }
        abort_unless($team
            ? DB::table('team_members')->where('team_id', $team->id)->where('user_id', $id)->whereIn('role', ['owner', 'member'])->exists()
            : $id === $user->id, 422, 'Исполнитель должен быть участником с правом редактирования.');

        return $id;
    }

    /** @return array<string, mixed> */
    public function props(User $user, ?Team $team): array
    {
        $teams = DB::table('teams')->join('team_members', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $user->id)->orderByRaw('teams.archived_at IS NOT NULL')->orderBy('teams.name')
            ->get(['teams.id', 'teams.name', 'teams.archived_at', 'team_members.role']);
        $members = $team ? DB::table('team_members')->join('users', 'users.id', '=', 'team_members.user_id')
            ->where('team_id', $team->id)->orderBy('users.id')->get(['users.id', 'users.name', 'team_members.role']) : [];

        if ($team) {
            $activeStages = [ParticipationStage::Studying->value, ParticipationStage::Preparing->value, ParticipationStage::Submitted->value];
            $applications = DB::table('tender_participations')->where('team_id', $team->id)->whereNotNull('assignee_id')
                ->whereIn('stage', $activeStages)->groupBy('assignee_id')->selectRaw('assignee_id, COUNT(*) as total')->pluck('total', 'assignee_id');
            $tasks = DB::table('tender_checklist_items')->join('tender_participations', 'tender_participations.id', '=', 'tender_checklist_items.participation_id')
                ->where('tender_participations.team_id', $team->id)->whereNotNull('tender_checklist_items.assignee_id')
                ->whereNull('tender_checklist_items.completed_at')->groupBy('tender_checklist_items.assignee_id')
                ->selectRaw('tender_checklist_items.assignee_id, COUNT(*) as total')->pluck('total', 'tender_checklist_items.assignee_id');
            $overdue = DB::table('tender_checklist_items')->join('tender_participations', 'tender_participations.id', '=', 'tender_checklist_items.participation_id')
                ->where('tender_participations.team_id', $team->id)->whereNotNull('tender_checklist_items.assignee_id')
                ->whereNull('tender_checklist_items.completed_at')->whereDate('tender_checklist_items.due_on', '<', now(app(TenderCalendarService::class)->timezone($user))->toDateString())
                ->groupBy('tender_checklist_items.assignee_id')->selectRaw('tender_checklist_items.assignee_id, COUNT(*) as total')
                ->pluck('total', 'tender_checklist_items.assignee_id');
            $members->transform(function (object $member) use ($applications, $tasks, $overdue): object {
                $member->active_applications = (int) ($applications[$member->id] ?? 0);
                $member->open_tasks = (int) ($tasks[$member->id] ?? 0);
                $member->overdue_tasks = (int) ($overdue[$member->id] ?? 0);

                return $member;
            });
        }

        return ['teams' => $teams, 'team' => $team ? ['id' => $team->id, 'name' => $team->name, 'role' => $this->role($user, $team),
            'archived_at' => $team->archived_at?->toAtomString()] : null,
            'members' => $members, 'can_edit' => ! $team || ($team->archived_at === null && $this->role($user, $team) !== 'viewer')];
    }
}
