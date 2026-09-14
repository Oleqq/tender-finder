<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\ParticipationApprovalRequest;
use App\Models\Team;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ParticipationApprovalService
{
    public function required(TenderParticipation $participation): bool
    {
        if (! $participation->team_id) {
            return false;
        }
        $settings = app(TeamWorkflowService::class)->settings(Team::query()->findOrFail($participation->team_id));
        if (! $settings->approval_enabled) {
            return false;
        }
        $revenueTrigger = $settings->approval_min_revenue !== null
            && (float) ($participation->planned_revenue ?? 0) >= (float) $settings->approval_min_revenue;
        $margin = app(TenderWorkService::class)->economics($participation)['planned_margin_percent'];
        $marginTrigger = $settings->approval_max_margin_percent !== null && $margin !== null
            && (float) $margin <= (float) $settings->approval_max_margin_percent;

        return ($settings->approval_min_revenue === null && $settings->approval_max_margin_percent === null)
            || $revenueTrigger || $marginTrigger;
    }

    public function approved(TenderParticipation $participation): bool
    {
        return ParticipationApprovalRequest::query()->where('participation_id', $participation->id)
            ->where('economics_version', $participation->economics_version)->where('status', 'approved')->exists();
    }

    public function assertMayAdvance(TenderParticipation $participation, string $stage): void
    {
        if (! in_array($stage, ['preparing', 'submitted', 'won'], true) || ! $this->required($participation)) {
            return;
        }
        abort_unless($participation->participation_decision === 'go', 409, 'Для продолжения зафиксируйте решение «Участвуем».');
        abort_unless($this->approved($participation), 409, 'Для перехода на следующий этап требуется согласование go/no-go.');
    }

    public function request(User $actor, Team $team, TenderParticipation $participation, ?string $note): ParticipationApprovalRequest
    {
        abort_unless($participation->team_id === $team->id, 404);
        abort_unless($participation->participation_decision === 'go', 422, 'Сначала сохраните решение «Участвуем».');
        abort_unless($this->required($participation), 422, 'Для текущих показателей согласование не требуется.');

        $approval = DB::transaction(function () use ($actor, $team, $participation, $note): ParticipationApprovalRequest {
            TenderParticipation::query()->whereKey($participation->id)->lockForUpdate()->firstOrFail();
            $existing = ParticipationApprovalRequest::query()->where('participation_id', $participation->id)
                ->where('economics_version', $participation->economics_version)->whereIn('status', ['pending', 'approved'])->latest('id')->first();
            if ($existing) {
                return $existing;
            }
            ParticipationApprovalRequest::query()->where('participation_id', $participation->id)->where('status', 'pending')
                ->update(['status' => 'superseded', 'resolved_at' => now()]);
            $required = app(TeamWorkflowService::class)->settings($team)->required_approvals;
            $created = ParticipationApprovalRequest::query()->create([
                'participation_id' => $participation->id, 'requested_by_id' => $actor->id, 'status' => 'pending',
                'required_approvals' => $required, 'economics_version' => $participation->economics_version,
                'note' => $note ? trim($note) : null, 'version' => 1,
            ]);
            app(TeamActivityService::class)->record($team, $actor, 'approval_requested', ['participation_id' => $participation->id, 'approval_id' => $created->id]);

            return $created;
        });
        if ($approval->wasRecentlyCreated) {
            $this->notifyEditors($approval, $actor, 'requested');
        }

        return $approval;
    }

    public function vote(User $actor, Team $team, ParticipationApprovalRequest $approval, string $decision, ?string $comment, int $version): ParticipationApprovalRequest
    {
        return DB::transaction(function () use ($actor, $team, $approval, $decision, $comment, $version): ParticipationApprovalRequest {
            $locked = ParticipationApprovalRequest::query()->with('participation')->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->participation->team_id === $team->id, 404);
            abort_if($locked->status !== 'pending', 409, 'Согласование уже завершено.');
            abort_if($locked->economics_version !== $locked->participation->economics_version, 409, 'Экономика заявки изменилась. Создайте новое согласование.');
            abort_if($locked->version !== $version, 409, 'Согласование изменено другим участником. Обновите страницу.');
            $locked->votes()->updateOrCreate(['approver_id' => $actor->id], ['decision' => $decision, 'comment' => $comment ? trim($comment) : null]);
            $approved = $locked->votes()->where('decision', 'approved')->count();
            $rejected = $locked->votes()->where('decision', 'rejected')->exists();
            $status = $rejected ? 'rejected' : ($approved >= $locked->required_approvals ? 'approved' : 'pending');
            $locked->forceFill(['status' => $status, 'resolved_at' => $status === 'pending' ? null : now(), 'version' => $locked->version + 1])->save();
            app(TeamActivityService::class)->record($team, $actor, 'approval_voted', ['participation_id' => $locked->participation_id,
                'approval_id' => $locked->id, 'decision' => $decision, 'status' => $status]);
            if ($status !== 'pending') {
                $this->notifyRequester($locked, $actor);
            }

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    public function present(TenderParticipation $participation): array
    {
        $approval = ParticipationApprovalRequest::query()->where('participation_id', $participation->id)
            ->with('votes.approver:id,name')->latest('id')->first();

        return [
            'required' => $this->required($participation),
            'current' => $approval ? [
                'id' => $approval->id, 'status' => $approval->economics_version === $participation->economics_version ? $approval->status : 'superseded',
                'required_approvals' => $approval->required_approvals, 'economics_version' => $approval->economics_version,
                'note' => $approval->note, 'version' => $approval->version,
                'votes' => $approval->votes->map(fn ($vote): array => ['approver_id' => $vote->approver_id,
                    'approver_name' => $vote->approver?->name, 'decision' => $vote->decision, 'comment' => $vote->comment])->all(),
            ] : null,
        ];
    }

    public function stillDue(NotificationDelivery $delivery): bool
    {
        $approval = ParticipationApprovalRequest::query()->with('participation')->find($delivery->payload['approval_id'] ?? 0);
        if (! $approval || $approval->participation->economics_version !== $approval->economics_version) {
            return false;
        }
        $team = Team::query()->find($approval->participation->team_id);
        if (! $team || $team->archived_at !== null || app(TeamWorkspaceService::class)->role($delivery->user, $team) === null) {
            return false;
        }

        return ($delivery->payload['event'] ?? null) === 'requested' ? $approval->status === 'pending' : $approval->status !== 'pending';
    }

    private function notifyEditors(ParticipationApprovalRequest $approval, User $actor, string $event): void
    {
        $teamId = $approval->participation->team_id;
        $ids = DB::table('team_members')->where('team_id', $teamId)->whereIn('role', ['owner', 'member'])
            ->where('user_id', '!=', $actor->id)->pluck('user_id');
        foreach ($ids as $id) {
            $this->queue($approval, (int) $id, $event, 'approval:'.$approval->id.':requested:'.$id);
        }
    }

    private function notifyRequester(ParticipationApprovalRequest $approval, User $actor): void
    {
        if ($approval->requested_by_id && $approval->requested_by_id !== $actor->id) {
            $this->queue($approval, (int) $approval->requested_by_id, $approval->status, 'approval:'.$approval->id.':'.$approval->status);
        }
    }

    private function queue(ParticipationApprovalRequest $approval, int $userId, string $event, string $key): void
    {
        $user = User::query()->find($userId);
        if (! $user?->telegram_id || ! app(AccessService::class)->hasActiveAccess($user)) {
            return;
        }
        $p = $approval->participation;
        $delivery = NotificationDelivery::query()->firstOrCreate(['idempotency_key' => $key], [
            'user_id' => $userId, 'tender_id' => $p->tender_id, 'type' => 'participation_approval', 'status' => NotificationStatus::Queued,
            'scheduled_at' => now(), 'payload' => ['approval_id' => $approval->id, 'event' => $event,
                'title' => mb_substr($p->tender->title, 0, 500), 'url' => route('tenders.work', ['tender' => $p->tender_id, 'team_id' => $p->team_id])],
        ]);
        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }
    }
}
