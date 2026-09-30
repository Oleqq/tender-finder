<?php

namespace App\Services;

use App\Enums\QueryStatus;
use App\Models\RostenderFeedSearchQuery;
use App\Models\SearchQuery;
use App\Models\SourceFeed;
use App\Models\SourceRun;
use Illuminate\Support\Collection;

final class MonitoringStatusService
{
    /**
     * @param  Collection<int, SearchQuery>  $queries
     * @return array<int, list<array<string, bool|int|string|null>>>
     */
    public function forQueries(Collection $queries): array
    {
        $result = $queries->mapWithKeys(fn (SearchQuery $query): array => [$query->id => []])->all();
        $queryIds = $queries->map(fn (SearchQuery $query): int => $query->id)->all();

        if ($queryIds === []) {
            return $result;
        }

        $rostenderLinks = RostenderFeedSearchQuery::query()->whereIn('search_query_id', $queryIds)->get();
        $links = $rostenderLinks;
        $feeds = SourceFeed::query()->whereIn('id', $links->pluck('source_feed_id')->unique())->get()->keyBy('id');

        foreach ($queries as $query) {
            foreach ($links->where('search_query_id', $query->id) as $link) {
                /** @var SourceFeed|null $feed */
                $feed = $feeds->get($link->source_feed_id);
                if ($feed === null) {
                    continue;
                }

                $result[$query->id][] = $this->status($query, $feed);
            }
        }

        return $result;
    }

    /** @return array<string, bool|int|string|null> */
    private function status(SearchQuery $query, SourceFeed $feed): array
    {
        /** @var SourceRun|null $success */
        $success = SourceRun::query()->where('source_feed_id', $feed->id)->where('status', 'succeeded')->latest('finished_at')->first();
        /** @var SourceRun|null $failure */
        $failure = SourceRun::query()->where('source_feed_id', $feed->id)->where('status', 'failed')->latest('finished_at')->first();
        $lastSuccessAt = $success?->finished_at;
        $lastFailureAt = $failure?->finished_at;
        $hasCurrentFailure = $lastFailureAt !== null && ($lastSuccessAt === null || $lastFailureAt->gt($lastSuccessAt));
        $waitingForQueue = ! $hasCurrentFailure
            && $feed->last_attempt_at !== null
            && ($lastSuccessAt === null || $feed->last_attempt_at->gt($lastSuccessAt));

        [$state, $message] = match (true) {
            $query->status !== QueryStatus::Active => ['paused', 'Мониторинг остановлен, поэтому новый опрос для него не планируется.'],
            $hasCurrentFailure => ['error', $this->failureMessage($feed->source, $failure->error_code)],
            $waitingForQueue => ['queued', 'Опрос поставлен в очередь. Источник ещё не подтвердил ответ.'],
            $success !== null && $success->items_seen === 0 => ['empty', 'Источник ответил: новых записей в последнем ответе нет.'],
            $success !== null => ['ok', 'Источник ответил. Совпадения показываются отдельно в результатах мониторинга.'],
            default => ['pending', 'Первый опрос ещё не завершён.'],
        };

        return [
            'source' => $feed->source,
            'state' => $state,
            'message' => $message,
            'last_success_at' => $lastSuccessAt?->toAtomString(),
            'last_success_items_seen' => $success?->items_seen,
            'last_failure_at' => $lastFailureAt?->toAtomString(),
            'next_attempt_at' => $query->status === QueryStatus::Active ? $feed->next_poll_at?->toAtomString() : null,
            'manual_retry_blocked' => $hasCurrentFailure
                && in_array($failure->error_code, ['quota_exhausted', 'remote_quota_or_access_denied'], true)
                && $feed->next_poll_at?->isFuture(),
        ];
    }

    private function failureMessage(string $source, ?string $code): string
    {
        if ($code === 'quota_exhausted') {
            return 'Суточный лимит RosTender исчерпан. Следующая попытка запланирована после обновления лимита.';
        }

        if ($code === 'tls_failed') {
            return 'Не удалось безопасно подключиться к источнику. Следующая попытка запланирована.';
        }

        return 'RosTender временно не ответил. Следующая попытка запланирована.';
    }
}
