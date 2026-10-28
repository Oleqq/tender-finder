<?php

namespace App\Services\PriceCatalog;

use App\Models\PriceCatalog;
use App\Models\PriceCatalogComparison;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\TenderAnalyticsService;
use App\Services\TenderSources;
use App\Services\TenderWorkService;

final class CatalogService
{
    public function __construct(private readonly CatalogMatcher $matcher) {}

    public function enabled(User $user): bool
    {
        return in_array(app(TenderAnalyticsService::class)->tierFor($user), ['pro', 'ultra'], true);
    }

    public function catalog(User $user): ?PriceCatalog
    {
        return PriceCatalog::query()->where('user_id', $user->id)->first();
    }

    /** @return array<string, mixed> */
    public function present(User $user): array
    {
        $catalog = $this->catalog($user);

        return ['version' => $catalog->version ?? 0, 'filename' => $catalog?->filename,
            'items' => $catalog?->items()->orderBy('id')->get(['id', 'name', 'characteristics', 'price', 'quantity', 'unit', 'region'])->toArray() ?? []];
    }

    public function fingerprint(Tender $tender): string
    {
        return hash('sha256', json_encode([$tender->title, $tender->description, $tender->region,
            $tender->metadata, $tender->budget_amount, $tender->deadline_at?->toAtomString()], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function comparison(User $user, Tender $tender): array
    {
        $catalog = $this->present($user);
        $saved = PriceCatalogComparison::query()->where('user_id', $user->id)->where('tender_id', $tender->id)->first();
        $hash = $this->fingerprint($tender);
        $requirements = $saved->requirements ?? [[
            'name' => $tender->title, 'characteristics' => null, 'quantity' => null, 'unit' => null,
            'region' => $tender->region, 'price' => null,
        ]];
        $counts = ['matched' => 0, 'uncertain' => 0, 'missing' => 0, 'confirmed' => 0];
        $rows = [];
        foreach ($requirements as $index => $requirement) {
            $candidates = $this->matcher->candidates($requirement, $catalog['items']);
            $status = $candidates[0]['status'] ?? 'missing';
            $review = $saved?->reviews[$index] ?? null;
            if ($review !== null && (($review['catalog_version'] ?? null) !== $catalog['version'] || ($review['tender_hash'] ?? null) !== $hash)) {
                $review = null;
            }
            $selected = null;
            if ($review !== null) {
                $status = $review['status'];
                if ($status === 'matched') {
                    $counts['confirmed']++;
                }
                foreach ($catalog['items'] as $item) {
                    if ($item['id'] === ($review['item_id'] ?? null)) {
                        $selected = ['item' => $item, 'checks' => $this->matcher->checks($requirement, $item)];
                        break;
                    }
                }
            }
            $counts[$status]++;
            $rows[] = ['index' => $index, 'requirement' => $requirement, 'status' => $status,
                'candidates' => $candidates, 'review' => $review, 'selected' => $selected];
        }

        $groups = [];
        foreach ($rows as $i => $row) {
            $rows[$i]['stock_check'] = null;
            $chosen = $row['selected'] ?? ($row['candidates'][0] ?? null);
            if ($row['status'] === 'missing' || $chosen === null) {
                continue;
            }
            $unitChecks = array_filter($chosen['checks'], fn (array $check): bool => $check['label'] === 'Единица измерения' && $check['state'] === 'ok');
            if ($unitChecks !== []) {
                $groups[$chosen['item']['id']][] = $i;
            }
        }
        foreach ($groups as $indices) {
            if (count($indices) < 2) {
                continue;
            }
            $total = 0;
            $unknown = false;
            foreach ($indices as $i) {
                $quantity = $rows[$i]['requirement']['quantity'];
                $unknown = $unknown || $quantity === null;
                $total += (int) str_replace('.', '', (string) $quantity);
            }
            $first = $rows[$indices[0]];
            $item = ($first['selected'] ?? $first['candidates'][0])['item'];
            $stock = $item['quantity'];
            $state = $unknown || $stock === null ? 'unknown' : ($total <= (int) str_replace('.', '', $stock) ? 'ok' : 'different');
            foreach ($indices as $i) {
                $rows[$i]['stock_check'] = ['label' => 'Суммарный запас', 'state' => $state,
                    'detail' => $state === 'ok' ? 'Запаса хватает на повторяющиеся строки этой спецификации.'
                        : ($state === 'unknown' ? 'Для повторяющихся строк недостаточно данных о количестве.' : 'Одной позиции прайса не хватает на суммарное количество повторяющихся строк.')];
                if ($state !== 'ok' && $rows[$i]['review'] === null && $rows[$i]['status'] === 'matched') {
                    $counts['matched']--;
                    $counts['uncertain']++;
                    $rows[$i]['status'] = 'uncertain';
                }
            }
        }

        return ['catalog_version' => $catalog['version'], 'version' => $saved->version ?? 0, 'tender_hash' => $hash,
            'filename' => $saved?->filename, 'has_specification' => $saved?->requirements !== null,
            'complete' => $saved?->requirements !== null && $saved->complete,
            'counts' => $counts, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    public function recommendations(User $user): array
    {
        $items = $this->present($user)['items'];
        $index = [];
        foreach ($items as $item) {
            foreach ($this->matcher->tokens($item['name']) as $token) {
                $index[$token][] = $item;
            }
        }
        $tenders = app(TenderWorkService::class)->accessible($user)
            ->whereHas('matches.searchQuery', fn ($q) => $q->where('user_id', $user->id)->where('status', '!=', 'deleted'))
            ->whereDoesntHave('userStates', fn ($q) => $q->where('user_id', $user->id)->whereIn('status', ['dismissed', 'archived']))
            ->whereNotIn('id', TenderParticipation::onlyTrashed()->whereNull('team_id')->where('user_id', $user->id)->select('tender_id'))
            ->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>', now()))
            ->orderByDesc('id')->limit(301)
            ->select(['id', 'source', 'title', 'region', 'deadline_at'])
            ->selectRaw('SUBSTR(description, 1, 4000) AS description')->get();
        $results = [];
        foreach ($tenders->take(300) as $tender) {
            $text = array_fill_keys($this->matcher->tokens($tender->title.' '.mb_substr(strip_tags($tender->description ?? ''), 0, 4000)), true);
            $pool = [];
            foreach (array_keys($text) as $token) {
                foreach ($index[$token] ?? [] as $item) {
                    $pool[$item['id']] = $item;
                }
            }
            $hits = [];
            foreach ($pool as $item) {
                $tokens = $this->matcher->tokens($item['name']);
                $shared = array_values(array_intersect($tokens, array_keys($text)));
                if (count($shared) >= min(2, count($tokens))) {
                    $hits[] = ['id' => $item['id'], 'name' => $item['name'], 'terms' => $shared,
                        'score' => count($shared) / max(1, count($tokens))];
                }
            }
            if ($hits === []) {
                continue;
            }
            usort($hits, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $results[] = ['id' => $tender->id, 'title' => $tender->title, 'region' => $tender->region,
                'source_label' => TenderSources::label($tender->source), 'deadline_at' => $tender->deadline_at?->toAtomString(),
                'hits' => array_slice($hits, 0, 3), 'rank' => $hits[0]['score']];
        }
        usort($results, fn (array $a, array $b): int => ($b['rank'] <=> $a['rank']) ?: ($b['id'] <=> $a['id']));

        return ['items' => array_slice($results, 0, 20), 'examined' => min(300, $tenders->count()),
            'limited' => $tenders->count() > 300, 'total_found' => count($results)];
    }
}
