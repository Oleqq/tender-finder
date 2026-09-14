<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\DeliverTelegramNotification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Team;
use App\Models\TeamTenderReview;
use App\Models\TeamTenderRoutingRule;
use App\Models\TeamWorkflowSetting;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TeamWorkflowService
{
    public function settings(Team $team): TeamWorkflowSetting
    {
        return TeamWorkflowSetting::query()->firstOrCreate(['team_id' => $team->id], [
            'review_sla_hours' => 24,
            'assignment_mode' => 'manual',
            'notify_assignments' => true,
            'notify_sla' => true,
            'digest_enabled' => true,
            'digest_time' => '09:00',
            'approval_enabled' => false,
            'required_approvals' => 1,
            'version' => 1,
        ]);
    }

    public function sync(Team $team): int
    {
        return DB::transaction(function () use ($team): int {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            if ($team->archived_at !== null) {
                return 0;
            }

            $settings = $this->settings($team);
            $matches = DB::table('tender_query_matches')
                ->join('team_search_queries', 'team_search_queries.search_query_id', '=', 'tender_query_matches.search_query_id')
                ->join('search_queries', 'search_queries.id', '=', 'team_search_queries.search_query_id')
                ->where('team_search_queries.team_id', $team->id)
                ->where('search_queries.status', '!=', 'deleted')
                ->groupBy('tender_query_matches.tender_id')
                ->selectRaw('tender_query_matches.tender_id, MIN(tender_query_matches.matched_at) as first_matched_at')
                ->get();
            $created = 0;

            foreach ($matches as $match) {
                if (TeamTenderReview::query()->where('team_id', $team->id)->where('tender_id', $match->tender_id)->exists()) {
                    continue;
                }
                $tender = Tender::query()->find($match->tender_id);
                if (! $tender) {
                    continue;
                }
                $matchedAt = Carbon::parse($match->first_matched_at);
                $assignee = $this->chooseAssignee($team, $tender, $settings);
                $review = TeamTenderReview::query()->create([
                    'team_id' => $team->id,
                    'tender_id' => $tender->id,
                    'status' => 'new',
                    'assignee_id' => $assignee,
                    'due_at' => $matchedAt->copy()->addHours($settings->review_sla_hours),
                    'assigned_at' => $assignee ? now() : null,
                    'version' => 1,
                ]);
                if ($assignee) {
                    $this->queue($review, $assignee, 'team_review_assignment', 'assignment:'.$review->id.':'.$assignee.':1');
                }
                $created++;
            }

            return $created;
        });
    }

    public function queueDue(): void
    {
        Team::query()->whereNull('archived_at')->lazyById()->each(function (Team $team): void {
            $this->sync($team);
            $settings = $this->settings($team);
            if ($settings->notify_sla) {
                TeamTenderReview::query()->where('team_id', $team->id)->whereIn('status', ['new', 'reviewing', 'deferred'])
                    ->whereNotNull('assignee_id')->whereNotNull('due_at')->where('due_at', '<=', now())->whereNull('sla_alerted_at')
                    ->lazyById()->each(function (TeamTenderReview $review): void {
                        $this->queue($review, (int) $review->assignee_id, 'team_review_sla', 'review-sla:'.$review->id.':'.$review->due_at?->timestamp);
                        $review->forceFill(['sla_alerted_at' => now()])->save();
                    });
            }
            $this->queueDigest($team, $settings);
        });
    }

    public function stillDue(NotificationDelivery $delivery): bool
    {
        if ($delivery->type === 'team_review_digest') {
            $team = Team::query()->find($delivery->payload['team_id'] ?? 0);

            return $team !== null && $team->archived_at === null && $team->owner_id === $delivery->user_id;
        }
        $review = TeamTenderReview::query()->find($delivery->payload['review_id'] ?? 0);
        if (! $review || in_array($review->status, ['qualified', 'rejected'], true)) {
            return false;
        }
        $team = Team::query()->find($review->team_id);
        if (! $team || $team->archived_at !== null || app(TeamWorkspaceService::class)->role($delivery->user, $team) === null) {
            return false;
        }

        return match ($delivery->type) {
            'team_review_assignment' => $review->assignee_id === $delivery->user_id,
            'team_review_sla' => $review->assignee_id === $delivery->user_id && $review->due_at?->isPast(),
            default => false,
        };
    }

    public function notifyAssignment(TeamTenderReview $review): void
    {
        if ($review->assignee_id) {
            $this->queue($review, (int) $review->assignee_id, 'team_review_assignment',
                'assignment:'.$review->id.':'.$review->assignee_id.':'.$review->version);
        }
    }

    private function chooseAssignee(Team $team, Tender $tender, TeamWorkflowSetting $settings): ?int
    {
        $memberIds = DB::table('team_members')->where('team_id', $team->id)->whereIn('role', ['owner', 'member'])
            ->orderBy('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        if ($memberIds === []) {
            return null;
        }

        $queryIds = DB::table('tender_query_matches')->join('team_search_queries', 'team_search_queries.search_query_id', '=', 'tender_query_matches.search_query_id')
            ->where('team_search_queries.team_id', $team->id)->where('tender_query_matches.tender_id', $tender->id)
            ->pluck('tender_query_matches.search_query_id')->map(fn ($id): int => (int) $id)->all();
        $rule = TeamTenderRoutingRule::query()->where('team_id', $team->id)->where('enabled', true)
            ->orderBy('priority')->orderBy('id')->get()->first(function (TeamTenderRoutingRule $rule) use ($tender, $queryIds, $memberIds): bool {
                return in_array((int) $rule->assignee_id, $memberIds, true)
                    && ($rule->source === null || $rule->source === $tender->source)
                    && ($rule->search_query_id === null || in_array((int) $rule->search_query_id, $queryIds, true))
                    && ($rule->region === null || mb_stripos((string) $tender->region, $rule->region) !== false)
                    && ($rule->min_budget === null || (float) $tender->budget_amount >= (float) $rule->min_budget);
            });
        if ($rule) {
            return (int) $rule->assignee_id;
        }
        if ($settings->assignment_mode === 'least_loaded') {
            $loads = TeamTenderReview::query()->where('team_id', $team->id)->whereIn('status', ['new', 'reviewing', 'deferred'])
                ->whereIn('assignee_id', $memberIds)->groupBy('assignee_id')->selectRaw('assignee_id, COUNT(*) AS total')->pluck('total', 'assignee_id');
            usort($memberIds, fn (int $a, int $b): int => ((int) ($loads[$a] ?? 0) <=> (int) ($loads[$b] ?? 0)) ?: ($a <=> $b));

            return $memberIds[0];
        }
        if ($settings->assignment_mode === 'round_robin') {
            $cursor = array_search((int) $settings->assignment_cursor_id, $memberIds, true);
            $next = $cursor === false ? $memberIds[0] : $memberIds[($cursor + 1) % count($memberIds)];
            $settings->forceFill(['assignment_cursor_id' => $next])->save();

            return $next;
        }

        return null;
    }

    private function queue(TeamTenderReview $review, int $userId, string $type, string $key): void
    {
        $settings = $this->settings($review->team);
        if ($type === 'team_review_assignment' && ! $settings->notify_assignments) {
            return;
        }
        $user = User::query()->find($userId);
        if (! $user?->telegram_id || ! app(AccessService::class)->hasActiveAccess($user)) {
            return;
        }
        $delivery = NotificationDelivery::query()->firstOrCreate(['idempotency_key' => $key], [
            'user_id' => $userId, 'tender_id' => $review->tender_id, 'type' => $type, 'status' => NotificationStatus::Queued,
            'scheduled_at' => now(), 'payload' => ['review_id' => $review->id, 'team_id' => $review->team_id,
                'title' => mb_substr($review->tender->title, 0, 500), 'due_at' => $review->due_at?->toAtomString(),
                'url' => route('tenders', ['team_id' => $review->team_id])],
        ]);
        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }
    }

    private function queueDigest(Team $team, TeamWorkflowSetting $settings): void
    {
        if (! $settings->digest_enabled) {
            return;
        }
        $owner = User::query()->find($team->owner_id);
        if (! $owner?->telegram_id || ! app(AccessService::class)->hasActiveAccess($owner)) {
            return;
        }
        $timezone = NotificationPreference::query()->where('user_id', $owner->id)->value('timezone') ?? 'Europe/Moscow';
        $localNow = now($timezone);
        if ($localNow->format('H:i') !== substr((string) $settings->digest_time, 0, 5)) {
            return;
        }
        $open = TeamTenderReview::query()->where('team_id', $team->id)->whereIn('status', ['new', 'reviewing', 'deferred']);
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['idempotency_key' => 'team-review-digest:'.$team->id.':'.$localNow->format('Ymd')],
            ['user_id' => $owner->id, 'type' => 'team_review_digest', 'status' => NotificationStatus::Queued, 'scheduled_at' => now(),
                'payload' => ['team_id' => $team->id, 'team_name' => $team->name, 'open' => (clone $open)->count(),
                    'overdue' => (clone $open)->where('due_at', '<', now())->count(), 'url' => route('tenders', ['team_id' => $team->id])]],
        );
        if ($delivery->wasRecentlyCreated) {
            DeliverTelegramNotification::dispatch($delivery->id)->afterCommit();
        }
    }
}
