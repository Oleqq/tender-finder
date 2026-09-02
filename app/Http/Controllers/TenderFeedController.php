<?php

namespace App\Http\Controllers;

use App\Enums\TenderUserStatus;
use App\Models\SearchQuery;
use App\Models\Tender;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenderFeedController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...array_column(TenderUserStatus::cases(), 'value')])],
            'tag' => ['nullable', 'string', 'max:40'],
            'query_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(['matched_desc', 'deadline_asc', 'budget_desc', 'budget_asc'])],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $tag = trim((string) ($filters['tag'] ?? ''));
        $queryId = isset($filters['query_id']) ? (int) $filters['query_id'] : null;
        $sort = (string) ($filters['sort'] ?? 'matched_desc');

        $matches = TenderQueryMatch::query()
            ->whereHas('searchQuery', function (Builder $query) use ($user, $queryId): void {
                $query->where('user_id', $user->id);

                if ($queryId !== null) {
                    $query->whereKey($queryId);
                }
            })
            ->with([
                'searchQuery:id,name',
                'tender',
                'tender.userStates' => fn ($query) => $query->where('user_id', $user->id),
            ]);

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $matches->whereHas('tender', fn (Builder $query) => $query->where(function (Builder $query) use ($needle): void {
                $customerExpression = config('database.default') === 'pgsql'
                    ? "metadata->>'customer'"
                    : "json_extract(metadata, '$.customer')";

                $query
                    ->whereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(description, ?)) LIKE ?', ['', $needle])
                    ->orWhereRaw('LOWER(COALESCE(reg_number, ?)) LIKE ?', ['', $needle])
                    ->orWhereRaw("LOWER(COALESCE({$customerExpression}, ?)) LIKE ?", ['', $needle]);
            }));
        }

        if ($status !== 'all') {
            $matches->where(function (Builder $matches) use ($status, $user): void {
                if ($status === TenderUserStatus::New->value) {
                    $matches->whereDoesntHave('tender.userStates', fn (Builder $query) => $query
                        ->where('user_id', $user->id))
                        ->orWhereHas('tender.userStates', fn (Builder $query) => $query
                            ->where('user_id', $user->id)
                            ->where('status', $status));

                    return;
                }

                $matches->whereHas('tender.userStates', fn (Builder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('status', $status));
            });
        }

        if ($tag !== '') {
            $matches->whereHas('tender.userStates', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->whereJsonContains('tags', $tag));
        }

        $this->applySort($matches, $sort);

        $paginator = $matches->paginate(12)->withQueryString();
        $paginator->through(function (TenderQueryMatch $match): array {
            $state = $match->tender->userStates->first();

            return [
                'id' => $match->id,
                'tender_id' => $match->tender->id,
                'title' => $match->tender->title,
                'description' => $match->tender->description,
                'canonical_url' => $match->tender->canonical_url,
                'reg_number' => $match->tender->reg_number,
                'region' => $match->tender->region,
                'budget_amount' => $match->tender->budget_amount,
                'currency' => $match->tender->currency,
                'deadline_at' => $match->tender->deadline_at?->toAtomString(),
                'matched_at' => $match->matched_at->toAtomString(),
                'query_name' => $match->searchQuery->name,
                'status' => $state?->status->value ?? TenderUserStatus::New->value,
                'tags' => $this->tags($state),
                'next_action_on' => $state?->next_action_on?->format('Y-m-d'),
                'match_reasons' => $this->reasonLabels($match->match_reasons ?? []),
            ];
        });

        return Inertia::render('Tenders', [
            'tenderMatches' => $paginator,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'tag' => $tag,
                'query_id' => $queryId,
                'sort' => $sort,
            ],
            'filterOptions' => [
                'queries' => SearchQuery::query()
                    ->where('user_id', $user->id)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'tags' => TenderUserState::query()
                    ->where('user_id', $user->id)
                    ->get(['tags'])
                    ->flatMap(fn (TenderUserState $state): array => $state->tags ?? [])
                    ->filter(fn (string $tag): bool => $tag !== '')
                    ->unique()
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                    ->values(),
            ],
            'savedViews' => $user->tenderFeedViews()
                ->latest()
                ->get(['id', 'name', 'filters']),
        ]);
    }

    /** @param Builder<TenderQueryMatch> $matches */
    private function applySort(Builder $matches, string $sort): void
    {
        $deadline = Tender::query()->select('deadline_at')
            ->whereColumn('tenders.id', 'tender_query_matches.tender_id');
        $budget = Tender::query()->select('budget_amount')
            ->whereColumn('tenders.id', 'tender_query_matches.tender_id');

        match ($sort) {
            'deadline_asc' => $matches->orderByRaw('('.$deadline->toSql().') IS NULL')->orderBy($deadline),
            'budget_desc' => $matches->orderByRaw('('.$budget->toSql().') IS NULL')->orderByDesc($budget),
            'budget_asc' => $matches->orderByRaw('('.$budget->toSql().') IS NULL')->orderBy($budget),
            default => $matches->latest('matched_at'),
        };

        $matches->orderByDesc('tender_query_matches.id');
    }

    /** @return list<string> */
    private function tags(?TenderUserState $state): array
    {
        return $state === null ? [] : ($state->tags ?? []);
    }

    /** @param array<string, mixed> $reasons
     * @return list<string>
     */
    private function reasonLabels(array $reasons): array
    {
        $labels = [];

        if (($reasons['keywords'] ?? []) !== []) {
            $labels[] = 'ключевые слова';
        }

        if (($reasons['region'] ?? null) === 'matched') {
            $labels[] = 'регион';
        }

        if (($reasons['budget'] ?? null) === 'matched') {
            $labels[] = 'сумма';
        }

        if (($reasons['deadline'] ?? null) === 'matched') {
            $labels[] = 'срок';
        }

        return $labels === [] ? ['настройки мониторинга'] : $labels;
    }
}
