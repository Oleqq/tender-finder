<?php

namespace App\Services;

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

    public function authorize(User $user, Team $team, bool $write = false, bool $owner = false): void
    {
        $role = $this->role($user, $team);
        abort_unless($role !== null, 404);
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
            ->where('team_members.user_id', $user->id)->orderBy('teams.name')->get(['teams.id', 'teams.name', 'team_members.role']);
        $members = $team ? DB::table('team_members')->join('users', 'users.id', '=', 'team_members.user_id')
            ->where('team_id', $team->id)->orderBy('users.id')->get(['users.id', 'users.name', 'team_members.role']) : [];

        return ['teams' => $teams, 'team' => $team ? ['id' => $team->id, 'name' => $team->name, 'role' => $this->role($user, $team)] : null,
            'members' => $members, 'can_edit' => ! $team || $this->role($user, $team) !== 'viewer'];
    }
}
