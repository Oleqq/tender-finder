<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\TenderChecklistItem;
use App\Models\TenderParticipation;
use App\Models\User;
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

        return Inertia::render('Teams', [...$work->props($request->user(), $team), 'invitations' => $invitations]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $team = DB::transaction(function () use ($request, $data): Team {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if(Team::query()->where('owner_id', $request->user()->id)->count() >= 10, 422, 'Можно создать до 10 команд.');
            $team = Team::query()->create(['name' => $data['name'], 'owner_id' => $request->user()->id]);
            DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $request->user()->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

            return $team;
        });

        return response()->json(['team_id' => $team->id], 201);
    }

    public function invite(Request $request, Team $team, TeamWorkspaceService $work): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(['member', 'viewer'])]]);
        $token = Str::random(64);
        DB::transaction(function () use ($request, $team, $work, $data, $token): void {
            $work->lock($request->user(), $team);
            $work->authorize($request->user(), $team, true, true);
            abort_if(DB::table('team_invitations')->where('team_id', $team->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 50, 422, 'Слишком много активных приглашений.');
            DB::table('team_invitations')->insert(['team_id' => $team->id, 'token_hash' => hash('sha256', $token), 'role' => $data['role'],
                'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['url' => url('/team-invitations/'.$token)], 201)->header('Cache-Control', 'no-store');
    }

    public function invitation(Request $request, string $token): Response
    {
        $invite = DB::table('team_invitations')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->whereNull('accepted_at')->where('expires_at', '>', now())->first();
        abort_unless($invite !== null, 404);

        return Inertia::render('TeamInvitation', ['invitation' => ['team_name' => Team::query()->findOrFail($invite->team_id)->name, 'role' => $invite->role, 'token' => $token]]);
    }

    public function accept(Request $request, string $token, TeamWorkspaceService $work): JsonResponse
    {
        $teamId = DB::transaction(function () use ($request, $token): int {
            $invite = DB::table('team_invitations')->where('token_hash', hash('sha256', $token))->first();
            abort_unless($invite !== null, 404);
            Team::query()->whereKey($invite->team_id)->lockForUpdate()->firstOrFail();
            $invite = DB::table('team_invitations')->where('id', $invite->id)->lockForUpdate()->first();
            abort_if($invite->revoked_at || $invite->accepted_at || now()->gte($invite->expires_at), 410, 'Приглашение недействительно.');
            abort_if(DB::table('team_members')->where('team_id', $invite->team_id)->count() >= 100, 422, 'В команде допускается до 100 участников.');
            // An existing member must never acquire a different role via an invitation.
            DB::table('team_members')->insertOrIgnore(['team_id' => $invite->team_id, 'user_id' => $request->user()->id, 'role' => $invite->role, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('team_invitations')->where('id', $invite->id)->update(['accepted_at' => now(), 'updated_at' => now()]);

            return $invite->team_id;
        });

        return response()->json(['team_id' => $teamId]);
    }

    public function revoke(Request $request, Team $team, int $invitation, TeamWorkspaceService $work): JsonResponse
    {
        DB::transaction(function () use ($request, $team, $invitation, $work): void {
            $work->lock($request->user(), $team);
            $work->authorize($request->user(), $team, true, true);
            abort_unless(DB::table('team_invitations')->where('team_id', $team->id)->where('id', $invitation)->update(['revoked_at' => now()]) > 0, 404);
        });

        return response()->json(['ok' => true]);
    }

    public function member(Request $request, Team $team, int $member, TeamWorkspaceService $work): JsonResponse
    {
        $data = $request->isMethod('delete') ? [] : $request->validate(['role' => ['required', Rule::in(['member', 'viewer'])]]);
        DB::transaction(function () use ($request, $team, $member, $work, $data): void {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $selfLeave = $request->isMethod('delete') && $member === $request->user()->id;
            $work->authorize($request->user(), $team, ! $selfLeave, ! $selfLeave);
            abort_if($member === $team->owner_id, 422, 'Владелец должен оставаться в команде.');
            $row = DB::table('team_members')->where('team_id', $team->id)->where('user_id', $member);
            abort_unless($row->exists(), 404);
            if ($request->isMethod('delete')) {
                $row->delete();
            } else {
                $row->update(['role' => $data['role'], 'updated_at' => now()]);
            }
            if ($request->isMethod('delete') || $data['role'] === 'viewer') {
                $ids = TenderParticipation::query()->where('team_id', $team->id)->select('id');
                TenderChecklistItem::query()->whereIn('participation_id', $ids)->where('assignee_id', $member)->update(['assignee_id' => null, 'version' => DB::raw('version + 1')]);
                TenderParticipation::query()->where('team_id', $team->id)->where('assignee_id', $member)->update(['assignee_id' => null, 'version' => DB::raw('version + 1')]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
