<?php

namespace App\Http\Controllers;

use App\Models\ParticipationComment;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\ParticipationCommentService;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ParticipationCommentController extends Controller
{
    public function store(Request $request, Tender $tender, TenderWorkService $work, TeamWorkspaceService $scope, ParticipationCommentService $comments): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, $scope, true);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000'], 'mention_ids' => ['sometimes', 'array', 'max:30'],
            'mention_ids.*' => ['integer', 'distinct']]);
        $comment = DB::transaction(function () use ($user, $team, $participation, $scope, $comments, $data): ParticipationComment {
            $scope->lock($user, $team);
            $mentionIds = $this->mentionIds($team, $data['mention_ids'] ?? []);
            $comment = ParticipationComment::query()->create(['participation_id' => $participation->id, 'author_id' => $user->id,
                'body' => trim($data['body']), 'version' => 1]);
            $this->snapshot($comment, $user, 'created');
            $comments->syncMentions($comment, $user, $mentionIds);
            if ($team) {
                app(TeamActivityService::class)->record($team, $user, 'comment_created', ['participation_id' => $participation->id, 'comment_id' => $comment->id]);
            }

            return $comment;
        });
        $comments->markRead($participation, $user, $comment->id);

        return response()->json(['comments' => $comments->present($participation, $user, $team), 'comment_id' => $comment->id], 201);
    }

    public function update(Request $request, Tender $tender, ParticipationComment $comment, TenderWorkService $work, TeamWorkspaceService $scope, ParticipationCommentService $comments): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, $scope, true);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000'], 'version' => ['required', 'integer', 'min:1'],
            'mention_ids' => ['sometimes', 'array', 'max:30'], 'mention_ids.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($user, $team, $participation, $comment, $scope, $comments, $data): void {
            $scope->lock($user, $team);
            $comment = ParticipationComment::query()->whereKey($comment->id)->where('participation_id', $participation->id)->lockForUpdate()->firstOrFail();
            abort_if($comment->deleted_at !== null, 410, 'Комментарий удалён.');
            abort_unless($comment->author_id === $user->id, 403);
            abort_if($comment->version !== (int) $data['version'], 409, 'Комментарий изменён в другой вкладке. Обновите страницу.');
            $body = trim($data['body']);
            $mentionIds = $this->mentionIds($team, $data['mention_ids'] ?? []);
            $comment->update(['body' => $body, 'version' => $comment->version + 1, 'edited_at' => now()]);
            $this->snapshot($comment, $user, 'edited');
            $comments->syncMentions($comment, $user, $mentionIds);
        });

        return response()->json(['comments' => $comments->present($participation, $user, $team)]);
    }

    public function destroy(Request $request, Tender $tender, ParticipationComment $comment, TenderWorkService $work, TeamWorkspaceService $scope, ParticipationCommentService $comments): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, $scope, true);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($user, $team, $participation, $comment, $scope, $data): void {
            $scope->lock($user, $team);
            $comment = ParticipationComment::query()->whereKey($comment->id)->where('participation_id', $participation->id)->lockForUpdate()->firstOrFail();
            abort_if($comment->deleted_at !== null, 410, 'Комментарий уже удалён.');
            abort_unless($comment->author_id === $user->id || $team?->owner_id === $user->id, 403);
            abort_if($comment->version !== (int) $data['version'], 409, 'Комментарий изменён в другой вкладке. Обновите страницу.');
            $comment->update(['body' => null, 'version' => $comment->version + 1, 'deleted_at' => now()]);
            $this->snapshot($comment, $user, 'deleted');
            if ($team) {
                app(TeamActivityService::class)->record($team, $user, 'comment_deleted', ['participation_id' => $participation->id, 'comment_id' => $comment->id]);
            }
        });

        return response()->json(['comments' => $comments->present($participation, $user, $team)]);
    }

    /** @return array{User, Team|null, TenderParticipation} */
    private function context(Request $request, Tender $tender, TenderWorkService $work, TeamWorkspaceService $scope, bool $write): array
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = $scope->context($request, $write);
        $work->authorize($user, $tender, $team);
        $participation = $work->participations($user, $team)->where('tender_id', $tender->id)->firstOrFail();

        return [$user, $team, $participation];
    }

    /** @param array<int, int> $ids
     * @return array<int, int>
     */
    private function mentionIds(?Team $team, array $ids): array
    {
        abort_if($team === null && $ids !== [], 422, 'Упоминания доступны только в команде.');
        if ($team) {
            $valid = DB::table('team_members')->where('team_id', $team->id)->whereIn('user_id', $ids)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            abort_if(count($valid) !== count(array_unique($ids)), 422, 'Упоминать можно только участников команды.');
        }

        return array_map('intval', $ids);
    }

    private function snapshot(ParticipationComment $comment, User $editor, string $action): void
    {
        DB::table('participation_comment_versions')->insert(['comment_id' => $comment->id, 'editor_id' => $editor->id,
            'version' => $comment->version, 'action' => $action, 'body' => $comment->body, 'created_at' => now()]);
    }
}
