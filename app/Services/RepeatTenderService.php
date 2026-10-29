<?php

namespace App\Services;

use App\Models\RepeatTenderCandidate;
use App\Models\RepeatTenderWatch;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderUserState;
use App\Models\User;
use App\Services\PriceCatalog\CatalogMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class RepeatTenderService
{
    public function enabled(User $user): bool
    {
        return app(ParticipationExecutionService::class)->enabled($user);
    }

    /** @return Builder<RepeatTenderWatch> */
    public function watches(User $user): Builder
    {
        return RepeatTenderWatch::query()->where('user_id', $user->id);
    }

    /** @return Builder<Tender> */
    public function tenders(User $user): Builder
    {
        return app(TenderWorkService::class)->accessible($user)->whereIn('source', [...array_keys(TenderSources::LABELS), 'tenderguru_preview'])
            ->whereNotIn('id', TenderParticipation::onlyTrashed()->where('user_id', $user->id)->whereNull('team_id')->select('tender_id'));
    }

    public function available(User $user, int $tenderId): bool
    {
        return $this->tenders($user)->whereKey($tenderId)->exists();
    }

    /** @return array<string, mixed> */
    public function snapshot(Tender $tender): array
    {
        return ['id' => $tender->id, 'title' => $tender->title, 'reg_number' => $tender->reg_number,
            'url' => $tender->canonical_url, 'customer' => TenderFacts::customer($tender), 'region' => $tender->region,
            'budget_amount' => $tender->budget_amount, 'currency' => $tender->currency,
            'published_at' => $tender->published_at?->toAtomString(), 'deadline_at' => $tender->deadline_at?->toAtomString(),
            'description' => filled($tender->description) ? mb_substr($tender->description, 0, 12000) : null,
            'description_limited' => mb_strlen($tender->description ?? '') > 12000,
            'description_hash' => hash('sha256', $tender->description ?? ''), 'stage' => TenderFacts::snapshot($tender)['stage']];
    }

    /** @param array<string, mixed> $snapshot */
    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $original */
    public function distinctNotice(array $original, Tender $tender): bool
    {
        $matcher = app(CatalogMatcher::class);
        $reg = $matcher->normalize($tender->reg_number ?? '');

        return $original['id'] !== $tender->id && $original['url'] !== $tender->canonical_url
            && ($reg === '' || $reg !== $matcher->normalize($original['reg_number'] ?? ''));
    }

    /** @return array<string, mixed>|null */
    public function match(RepeatTenderWatch $watch, Tender $tender): ?array
    {
        $old = $watch->original;
        if (! $this->distinctNotice($old, $tender) || $old['published_at'] === null || $tender->published_at === null
            || $tender->published_at->isFuture() || $tender->published_at->lte($old['published_at']) || $tender->deadline_at?->isPast()) {
            return null;
        }
        $matcher = app(CatalogMatcher::class);
        $customer = TenderFacts::customer($tender);
        if (! filled($old['customer']) || ! filled($customer) || $matcher->normalize($old['customer']) !== $matcher->normalize($customer)) {
            return null;
        }
        $oldTerms = $matcher->tokens($old['title']);
        $newTerms = $matcher->tokens($tender->title);
        $shared = array_values(array_intersect($oldTerms, $newTerms));
        if (count($shared) < 2 || count($shared) / max(1, count($oldTerms), count($newTerms)) < .65) {
            return null;
        }

        return ['kind' => 'lexical', 'terms' => $shared, 'customer' => $customer];
    }

    /** Local checks only; user lock serializes polling, manual decisions and archive. */
    public function scanUser(int $userId, ?int $watchId = null): int
    {
        return DB::transaction(function () use ($userId, $watchId): int {
            $user = User::query()->lockForUpdate()->find($userId);
            if ($user === null) {
                return 0;
            }
            $watches = $this->watches($user)->whereNull('archived_at')->when($watchId, fn (Builder $q) => $q->whereKey($watchId))->get();
            if (! $this->enabled($user)) {
                // Skip the inactive period without deleting history or producing a backlog on renewal.
                foreach ($watches as $watch) {
                    $watch->update(['scan_after' => now()]);
                }

                return 0;
            }
            $found = 0;
            foreach ($watches as $watch) {
                if (! $this->available($user, $watch->tender_id)) {
                    continue;
                }
                $after = $watch->scan_after->copy()->max(now()->subDays(30));
                $rows = $this->tenders($user)->whereHas('matches', fn (Builder $q) => $q->where('matched_at', '>=', $after)
                    ->whereHas('searchQuery', fn (Builder $q) => $q->where('user_id', $user->id)->where('status', 'active')))
                    ->whereNotIn('id', TenderUserState::query()->where('user_id', $user->id)->whereIn('status', ['dismissed', 'archived'])->select('tender_id'))
                    ->orderByDesc('id')->limit(301)->get();
                $pending = RepeatTenderCandidate::query()->where('watch_id', $watch->id)->where('status', 'possible')->count();
                $limited = $rows->count() > 300 || $pending >= 100;
                foreach ($rows->take(300) as $tender) {
                    if ($pending >= 100) {
                        $limited = true;
                        break;
                    }
                    $basis = $this->match($watch, $tender);
                    if ($basis === null) {
                        continue;
                    }
                    $candidate = RepeatTenderCandidate::query()->firstOrCreate(['watch_id' => $watch->id, 'tender_id' => $tender->id],
                        ['snapshot' => $this->snapshot($tender), 'basis' => $basis, 'version' => 1]);
                    if ($candidate->wasRecentlyCreated) {
                        $pending++;
                        $found++;
                    }
                }
                $watch->update(['checked_at' => now(), 'scan_limited' => $limited]);
            }

            return $found;
        });
    }

    /** @return Builder<RepeatTenderCandidate> */
    public function alerts(User $user): Builder
    {
        return RepeatTenderCandidate::query()->whereIn('watch_id', $this->watches($user)->whereNull('archived_at')
            ->whereIn('tender_id', $this->tenders($user)->select('id'))->select('id'))
            ->whereIn('tender_id', $this->tenders($user)->select('id'))->whereNull('read_at')->where('status', '!=', 'dismissed');
    }

    public function unread(User $user): int
    {
        return $this->enabled($user) ? $this->alerts($user)->count() : 0;
    }

    /** @return array<string, mixed> */
    public function presentWatch(RepeatTenderWatch $watch, User $user): array
    {
        return ['id' => $watch->id, 'version' => $watch->version, 'reason' => $watch->reason, 'original' => $watch->original,
            'archived' => $watch->archived_at !== null, 'available' => $this->available($user, $watch->tender_id),
            'unread' => $this->alerts($user)->where('watch_id', $watch->id)->count(),
            'captured_at' => $watch->created_at?->toAtomString(),
            'checked_at' => $watch->checked_at?->toAtomString(), 'scan_limited' => $watch->scan_limited];
    }

    /** @return array<string, mixed> */
    public function presentCandidate(RepeatTenderCandidate $candidate, User $user): array
    {
        $current = $this->tenders($user)->find($candidate->tender_id);
        $latest = $current ? $this->snapshot($current) : null;

        return ['id' => $candidate->id, 'version' => $candidate->version, 'status' => $candidate->status,
            'snapshot' => $candidate->snapshot, 'latest' => $latest, 'latest_hash' => $latest ? $this->fingerprint($latest) : null,
            'changed' => $latest !== null && $this->fingerprint($latest) !== $this->fingerprint($candidate->snapshot),
            'basis' => $candidate->basis, 'evidence' => $candidate->evidence, 'decision_note' => $candidate->decision_note, 'unread' => $candidate->read_at === null];
    }
}
