<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\ParticipationComment;
use App\Models\Team;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ParticipationCommentService
{
    /** @return array<int, array<string, mixed>> */
    public function present(TenderParticipation $participation, User $viewer, ?Team $team): array
    {
        $comments = ParticipationComment::query()->with('author')->where('participation_id', $participation->id)->orderBy('id')->get();
        $ids = $comments->pluck('id');
        $mentions = DB::table('participation_comment_mentions')->join('users', 'users.id', '=', 'participation_comment_mentions.user_id')
            ->whereIn('comment_id', $ids)->orderBy('users.id')->get(['comment_id', 'users.id', 'users.name'])->groupBy('comment_id');
        $versions = DB::table('participation_comment_versions')->leftJoin('users', 'users.id', '=', 'participation_comment_versions.editor_id')
            ->whereIn('comment_id', $ids)->orderByDesc('participation_comment_versions.version')
            ->get(['comment_id', 'participation_comment_versions.version', 'action', 'body', 'participation_comment_versions.created_at', 'users.name as editor_name'])
            ->groupBy('comment_id');

        $result = [];
        foreach ($comments as $comment) {
            $commentMentions = [];
            foreach ($mentions->get($comment->id, collect()) as $mention) {
                $commentMentions[] = ['id' => $mention->id, 'name' => $mention->name];
            }
            $history = [];
            foreach ($versions->get($comment->id, collect()) as $version) {
                $history[] = ['version' => (int) $version->version, 'action' => $version->action, 'body' => $version->body,
                    'editor_name' => $version->editor_name ?? 'Удалённый пользователь',
                    'created_at' => Carbon::parse($version->created_at)->toAtomString()];
            }
            $result[] = [
                'id' => $comment->id, 'author_id' => $comment->author_id,
                'author_name' => $comment->author_id === null ? 'Удалённый пользователь' : $comment->author->name,
                'body' => $comment->body, 'version' => $comment->version,
                'edited_at' => $comment->edited_at?->toAtomString(), 'deleted_at' => $comment->deleted_at?->toAtomString(),
                'created_at' => $comment->created_at->toAtomString(),
                'can_edit' => $comment->deleted_at === null && $comment->author_id === $viewer->id,
                'can_delete' => $comment->deleted_at === null && ($comment->author_id === $viewer->id || ($team?->owner_id === $viewer->id)),
                'mentions' => $commentMentions, 'history' => $history,
            ];
        }

        return $result;
    }

    public function markRead(TenderParticipation $participation, User $user, ?int $throughCommentId = null): void
    {
        $last = $throughCommentId ?? (int) ParticipationComment::query()->where('participation_id', $participation->id)->max('id');
        DB::table('participation_comment_reads')->upsert([
            ['participation_id' => $participation->id, 'user_id' => $user->id, 'last_read_comment_id' => $last, 'updated_at' => now()],
        ], ['participation_id', 'user_id'], ['last_read_comment_id', 'updated_at']);
        DB::table('participation_comment_mentions')->where('user_id', $user->id)->whereNull('read_at')
            ->whereIn('comment_id', ParticipationComment::query()->where('participation_id', $participation->id)
                ->where('id', '<=', $last)->select('id'))
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function unreadCount(TenderParticipation $participation, User $user): int
    {
        $last = (int) DB::table('participation_comment_reads')->where('participation_id', $participation->id)
            ->where('user_id', $user->id)->value('last_read_comment_id');

        return ParticipationComment::query()->where('participation_id', $participation->id)->where('id', '>', $last)
            ->where('author_id', '<>', $user->id)->whereNull('deleted_at')->count();
    }

    /** @param array<int> $userIds */
    public function syncMentions(ParticipationComment $comment, User $actor, array $userIds): void
    {
        $existing = DB::table('participation_comment_mentions')->where('comment_id', $comment->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $userIds = array_values(array_unique(array_filter($userIds, fn (int $id): bool => $id !== $actor->id)));
        DB::table('participation_comment_mentions')->where('comment_id', $comment->id)->whereNotIn('user_id', $userIds)->delete();
        foreach (array_diff($userIds, $existing) as $userId) {
            DB::table('participation_comment_mentions')->insert(['comment_id' => $comment->id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $this->queueMention($comment, $actor, $userId);
        }
    }

    public function stillDue(NotificationDelivery $delivery): bool
    {
        $commentId = (int) (($delivery->payload ?? [])['comment_id'] ?? 0);

        return DB::table('participation_comment_mentions')->join('participation_comments', 'participation_comments.id', '=', 'participation_comment_mentions.comment_id')
            ->join('tender_participations', 'tender_participations.id', '=', 'participation_comments.participation_id')
            ->join('teams', 'teams.id', '=', 'tender_participations.team_id')
            ->join('team_members', function ($join) use ($delivery): void {
                $join->on('team_members.team_id', '=', 'teams.id')->where('team_members.user_id', $delivery->user_id);
            })->where('participation_comment_mentions.comment_id', $commentId)->where('participation_comment_mentions.user_id', $delivery->user_id)
            ->whereNull('participation_comment_mentions.read_at')->whereNull('participation_comments.deleted_at')->whereNull('teams.archived_at')->exists();
    }

    private function queueMention(ParticipationComment $comment, User $actor, int $userId): void
    {
        $comment->loadMissing('participation.tender');
        $participation = $comment->participation;
        if ($participation->team_id === null) {
            return;
        }
        $url = url('/tenders/'.$participation->tender_id.'/work?team_id='.$participation->team_id);
        $delivery = NotificationDelivery::query()->firstOrCreate(['idempotency_key' => 'participation-comment-mention:'.$comment->id.':'.$userId], [
            'user_id' => $userId,
            'tender_id' => $participation->tender_id,
            'type' => 'team_mention',
            'status' => NotificationStatus::Queued,
            'payload' => ['comment_id' => $comment->id, 'author' => $actor->name, 'title' => $participation->tender->title,
                'excerpt' => mb_substr((string) $comment->body, 0, 300), 'url' => $url],
            'scheduled_at' => now(),
        ]);
        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }
    }
}
