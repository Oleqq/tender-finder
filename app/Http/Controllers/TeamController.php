<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TenderChecklistItem;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TeamController extends Controller
{
    public function index(Request $request, TeamWorkspaceService $work): Response
    {
        $team = $work->context($request);
        $invitations = $team && $team->owner_id === $request->user()->id
            ? DB::table('team_invitations')->where('team_id', $team->id)->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->get(['id', 'role', 'expires_at']) : [];

        $activities = $team ? DB::table('team_activity_logs')->leftJoin('users', 'users.id', '=', 'team_activity_logs.actor_id')
            ->where('team_activity_logs.team_id', $team->id)->latest('team_activity_logs.id')->limit(50)
            ->get(['team_activity_logs.id', 'team_activity_logs.action', 'team_activity_logs.context', 'team_activity_logs.created_at', 'users.name as actor_name'])
            ->map(function (object $activity): object {
                $activity->context = $activity->context ? json_decode($activity->context, true, flags: JSON_THROW_ON_ERROR) : [];

                return $activity;
            }) : [];

        return Inertia::render('Teams', [...$work->props($request->user(), $team), 'invitations' => $invitations, 'activities' => $activities]);
    }

    public function store(Request $request, TeamActivityService $activity): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $team = DB::transaction(function () use ($request, $data, $activity): Team {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if(Team::query()->where('owner_id', $request->user()->id)->whereNull('archived_at')->count() >= 10, 422, 'Можно создать до 10 активных команд.');
            $team = Team::query()->create(['name' => $data['name'], 'owner_id' => $request->user()->id]);
            DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $request->user()->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
            $activity->record($team, $request->user(), 'team_created');

            return $team;
        });

        return response()->json(['team_id' => $team->id], 201);
    }

    public function invite(Request $request, Team $team, TeamWorkspaceService $work, TeamActivityService $activity): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(['member', 'viewer'])]]);
        $token = Str::random(64);
        DB::transaction(function () use ($request, $team, $work, $activity, $data, $token): void {
            $work->lock($request->user(), $team);
            $work->authorize($request->user(), $team, true, true);
            abort_if(DB::table('team_invitations')->where('team_id', $team->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 50, 422, 'Слишком много активных приглашений.');
            DB::table('team_invitations')->insert(['team_id' => $team->id, 'token_hash' => hash('sha256', $token), 'role' => $data['role'],
                'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
            $activity->record($team, $request->user(), 'invitation_created', ['role' => $data['role']]);
        });

        return response()->json(['url' => url('/team-invitations/'.$token)], 201)->header('Cache-Control', 'no-store');
    }

    public function invitation(Request $request, string $token): Response
    {
        $invite = DB::table('team_invitations')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->whereNull('accepted_at')->where('expires_at', '>', now())->first();
        abort_unless($invite !== null, 404);

        return Inertia::render('TeamInvitation', ['invitation' => ['team_name' => Team::query()->findOrFail($invite->team_id)->name, 'role' => $invite->role, 'token' => $token]]);
    }

    public function accept(Request $request, string $token, TeamActivityService $activity): JsonResponse
    {
        $teamId = DB::transaction(function () use ($request, $token, $activity): int {
            $invite = DB::table('team_invitations')->where('token_hash', hash('sha256', $token))->first();
            abort_unless($invite !== null, 404);
            $team = Team::query()->whereKey($invite->team_id)->lockForUpdate()->firstOrFail();
            abort_if($team->archived_at !== null, 410, 'Команда находится в архиве.');
            $invite = DB::table('team_invitations')->where('id', $invite->id)->lockForUpdate()->first();
            abort_if($invite->revoked_at || $invite->accepted_at || now()->gte($invite->expires_at), 410, 'Приглашение недействительно.');
            abort_if(DB::table('team_members')->where('team_id', $invite->team_id)->count() >= 100, 422, 'В команде допускается до 100 участников.');
            // An existing member must never acquire a different role via an invitation.
            $joined = DB::table('team_members')->insertOrIgnore(['team_id' => $invite->team_id, 'user_id' => $request->user()->id, 'role' => $invite->role, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_invitations')->where('id', $invite->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
            $activity->record($team, $request->user(), $joined ? 'member_joined' : 'invitation_accepted', ['member_id' => $request->user()->id, 'role' => $invite->role]);

            return $invite->team_id;
        });

        return response()->json(['team_id' => $teamId]);
    }

    public function revoke(Request $request, Team $team, int $invitation, TeamWorkspaceService $work, TeamActivityService $activity): JsonResponse
    {
        DB::transaction(function () use ($request, $team, $invitation, $work, $activity): void {
            $work->lock($request->user(), $team);
            $work->authorize($request->user(), $team, true, true);
            abort_unless(DB::table('team_invitations')->where('team_id', $team->id)->where('id', $invitation)->update(['revoked_at' => now()]) > 0, 404);
            $activity->record($team, $request->user(), 'invitation_revoked', ['invitation_id' => $invitation]);
        });

        return response()->json(['ok' => true]);
    }

    public function member(Request $request, Team $team, int $member, TeamWorkspaceService $work, TeamActivityService $activity): JsonResponse
    {
        $data = $request->isMethod('delete') ? [] : $request->validate(['role' => ['required', Rule::in(['member', 'viewer'])]]);
        DB::transaction(function () use ($request, $team, $member, $work, $activity, $data): void {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $selfLeave = $request->isMethod('delete') && $member === $request->user()->id;
            $work->authorize($request->user(), $team, ! $selfLeave, ! $selfLeave);
            abort_if($member === $team->owner_id, 422, 'Владелец должен оставаться в команде.');
            $row = DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member);
            abort_unless($row->exists(), 404);
            if ($request->isMethod('delete')) {
                $row->delete();
                $activity->record($team, $request->user(), $selfLeave ? 'member_left' : 'member_removed', ['member_id' => $member]);
            } else {
                $row->update(['role' => $data['role'], 'updated_at' => now()]);
                $activity->record($team, $request->user(), 'member_role_changed', ['member_id' => $member, 'role' => $data['role']]);
            }
            if ($request->isMethod('delete') || $data['role'] === 'viewer') {
                DB::table('team_search_queries')->where('team_id', $team->id)->where('shared_by_id', $member)->delete();
                $ids = TenderParticipation::query()->where('team_id', $team->id)->select('id');
                TenderChecklistItem::query()->whereIn('participation_id', $ids)->where('assignee_id', $member)->update(['assignee_id' => null, 'version' => DB::raw('version + 1')]);
                TenderParticipation::query()->where('team_id', $team->id)->where('assignee_id', $member)->update(['assignee_id' => null, 'version' => DB::raw('version + 1')]);
                DB::table('team_tender_reviews')->where('team_id', $team->id)->where('assignee_id', $member)
                    ->update(['assignee_id' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function transferOwnership(Request $request, Team $team, TeamWorkspaceService $work, TeamActivityService $activity): JsonResponse
    {
        $data = $request->validate(['member_id' => ['required', 'integer']]);
        DB::transaction(function () use ($request, $team, $work, $activity, $data): void {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $work->authorize($request->user(), $team, true, true);
            $newOwner = (int) $data['member_id'];
            abort_if($newOwner === $team->owner_id, 422, 'Этот участник уже владелец команды.');
            abort_unless(DB::table('team_members')->where('team_id', $team->id)->where('user_id', $newOwner)->where('role', 'member')->exists(), 422, 'Передать владение можно участнику с правом редактирования.');
            User::query()->whereKey($newOwner)->lockForUpdate()->firstOrFail();
            abort_if(Team::query()->where('owner_id', $newOwner)->whereNull('archived_at')->count() >= 10, 422, 'У нового владельца уже 10 активных команд.');
            $oldOwner = $team->owner_id;
            DB::table('team_members')->where('team_id', $team->id)->where('user_id', $oldOwner)->update(['role' => 'member', 'updated_at' => now()]);
            DB::table('team_members')->where('team_id', $team->id)->where('user_id', $newOwner)->update(['role' => 'owner', 'updated_at' => now()]);
            $team->update(['owner_id' => $newOwner]);
            $activity->record($team, $request->user(), 'ownership_transferred', ['previous_owner_id' => $oldOwner, 'new_owner_id' => $newOwner]);
        });

        return response()->json(['team_id' => $team->id]);
    }

    public function archive(Request $request, Team $team, TeamWorkspaceService $work, TeamActivityService $activity): JsonResponse
    {
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $team, $work, $activity, $data): void {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            // Restoring an archived team intentionally bypasses the usual archived-write guard.
            $work->authorize($request->user(), $team, false, true);
            $archived = (bool) $data['archived'];
            if (($team->archived_at !== null) === $archived) {
                return;
            }
            if (! $archived) {
                abort_if(Team::query()->where('owner_id', $team->owner_id)->whereNull('archived_at')->count() >= 10, 422, 'У владельца уже 10 активных команд.');
            }
            $team->update(['archived_at' => $archived ? now() : null]);
            $activity->record($team, $request->user(), $archived ? 'team_archived' : 'team_restored');
        });

        return response()->json(['team_id' => $team->id]);
    }

    public function destroy(Request $request, Team $team, TeamWorkspaceService $work): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string']]);
        DB::transaction(function () use ($request, $team, $work, $data): void {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $work->authorize($request->user(), $team, false, true);
            abort_if($team->archived_at === null, 409, 'Сначала перенесите команду в архив.');
            abort_unless(hash_equals($team->name, $data['name']), 422, 'Введите точное название команды.');
            $team->delete();
        });

        return response()->json(['deleted' => true]);
    }
}
