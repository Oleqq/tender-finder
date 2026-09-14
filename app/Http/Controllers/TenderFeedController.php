<?php

namespace App\Http\Controllers;

use App\Enums\TenderUserStatus;
use App\Models\SearchQuery;
use App\Models\Team;
use App\Models\TeamTenderRoutingRule;
use App\Models\Tender;
use App\Models\TenderFeedView;
use App\Models\TenderQueryMatch;
use App\Models\TenderUserState;
use App\Services\TeamWorkflowService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderFacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $scope = app(TeamWorkspaceService::class);
        $team = $scope->context($request);
        if ($team !== null) {
            return $this->teamIndex($request, $scope, $team);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...array_column(TenderUserStatus::cases(), 'value')])],
            'tag' => ['nullable', 'string', 'max:40'],
            'query_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(['all', 'rostender', 'eis_rss'])],
            'sort' => ['nullable', Rule::in(['matched_desc', 'deadline_asc', 'budget_desc', 'budget_asc'])],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $tag = trim((string) ($filters['tag'] ?? ''));
        $queryId = isset($filters['query_id']) ? (int) $filters['query_id'] : null;
        $source = (string) ($filters['source'] ?? 'all');
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

        if ($source !== 'all') {
            $matches->whereHas('tender', fn (Builder $query) => $query->where('source', $source));
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
                'search_query_id' => $match->search_query_id,
                'customer' => TenderFacts::customer($match->tender),
                'deadline_reminders_enabled' => (bool) $state?->deadline_reminders_enabled,
                'action_reminder_enabled' => (bool) $state?->action_reminder_enabled,
                'watch_changes' => (bool) $state?->watch_changes,
                'query_name' => $match->searchQuery->name,
                'source' => $match->tender->source,
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
                'source' => $source,
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

    private function teamIndex(Request $request, TeamWorkspaceService $scope, Team $team): Response
    {
        $filters = $request->validate([
            'team_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'new', 'reviewing', 'qualified', 'deferred', 'rejected'])],
            'query_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(['all', 'rostender', 'eis_rss'])],
            'sort' => ['nullable', Rule::in(['matched_desc', 'deadline_asc', 'budget_desc', 'budget_asc'])],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $queryId = isset($filters['query_id']) ? (int) $filters['query_id'] : null;
        $assigneeId = isset($filters['assignee_id']) ? (int) $filters['assignee_id'] : null;
        $source = (string) ($filters['source'] ?? 'all');
        $sort = (string) ($filters['sort'] ?? 'matched_desc');
        $overdue = (bool) ($filters['overdue'] ?? false);

        $sharedQueryIds = DB::table('team_search_queries')
            ->join('search_queries', 'search_queries.id', '=', 'team_search_queries.search_query_id')
            ->where('team_id', $team->id)
            ->where('search_queries.status', '!=', 'deleted')
            ->when($queryId !== null, fn ($query) => $query->where('search_query_id', $queryId))
            ->select('search_query_id');
        $tenders = Tender::query()
            ->whereHas('matches', fn (Builder $query) => $query->whereIn('search_query_id', clone $sharedQueryIds))
            ->with([
                'matches' => fn ($query) => $query->whereIn('search_query_id', clone $sharedQueryIds)->with('searchQuery:id,name'),
                'teamReviews' => fn ($query) => $query->where('team_id', $team->id)->with('comments.author:id,name'),
            ])
            ->withExists(['participations as team_participation_exists' => fn ($query) => $query->where('team_id', $team->id)]);

        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $customerExpression = config('database.default') === 'pgsql' ? "metadata->>'customer'" : "json_extract(metadata, '$.customer')";
            $tenders->where(function (Builder $query) use ($needle, $customerExpression): void {
                $query->whereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(description, ?)) LIKE ?', ['', $needle])
                    ->orWhereRaw('LOWER(COALESCE(reg_number, ?)) LIKE ?', ['', $needle])
                    ->orWhereRaw("LOWER(COALESCE({$customerExpression}, ?)) LIKE ?", ['', $needle]);
            });
        }
        if ($status !== 'all') {
            $tenders->where(function (Builder $query) use ($status, $team): void {
                $query->whereHas('teamReviews', fn (Builder $review) => $review->where('team_id', $team->id)->where('status', $status));
                if ($status === 'new') {
                    $query->orWhereDoesntHave('teamReviews', fn (Builder $review) => $review->where('team_id', $team->id));
                }
            });
        }
        if ($assigneeId !== null) {
            $tenders->whereHas('teamReviews', fn (Builder $query) => $query->where('team_id', $team->id)->where('assignee_id', $assigneeId));
        }
        if ($overdue) {
            $tenders->whereHas('teamReviews', fn (Builder $query) => $query->where('team_id', $team->id)
                ->whereIn('status', ['new', 'reviewing', 'deferred'])->where('due_at', '<', now()));
        }
        if ($source !== 'all') {
            $tenders->where('source', $source);
        }
        match ($sort) {
            'deadline_asc' => $tenders->orderByRaw('deadline_at IS NULL')->orderBy('deadline_at'),
            'budget_desc' => $tenders->orderByRaw('budget_amount IS NULL')->orderByDesc('budget_amount'),
            'budget_asc' => $tenders->orderByRaw('budget_amount IS NULL')->orderBy('budget_amount'),
            default => $tenders->orderByDesc(TenderQueryMatch::query()->select('matched_at')
                ->whereColumn('tender_query_matches.tender_id', 'tenders.id')
                ->whereIn('search_query_id', clone $sharedQueryIds)->latest('matched_at')->limit(1)),
        };
        $paginator = $tenders->orderByDesc('tenders.id')->paginate(12)->withQueryString();
        $paginator->through(function (Tender $tender): array {
            $review = $tender->teamReviews->first();
            $matches = $tender->matches;

            return [
                'id' => $tender->id,
                'tender_id' => $tender->id,
                'title' => $tender->title,
                'description' => $tender->description,
                'canonical_url' => $tender->canonical_url,
                'reg_number' => $tender->reg_number,
                'region' => $tender->region,
                'budget_amount' => $tender->budget_amount,
                'currency' => $tender->currency,
                'deadline_at' => $tender->deadline_at?->toAtomString(),
                'matched_at' => $matches->max('matched_at')?->toAtomString(),
                'customer' => TenderFacts::customer($tender),
                'query_names' => $matches->pluck('searchQuery.name')->unique()->values()->all(),
                'source' => $tender->source,
                'match_reasons' => $matches->flatMap(fn (TenderQueryMatch $match) => $this->reasonLabels($match->match_reasons ?? []))->unique()->values()->all(),
                'review' => [
                    'status' => $review === null ? 'new' : $review->status,
                    'assignee_id' => $review?->assignee_id,
                    'rejection_reason' => $review?->rejection_reason,
                    'version' => $review === null ? 0 : $review->version,
                    'due_at' => $review?->due_at?->toAtomString(),
                    'overdue' => $review?->due_at?->isPast() && in_array($review->status, ['new', 'reviewing', 'deferred'], true),
                    'comments' => $review?->comments->map(fn ($comment): array => [
                        'id' => $comment->id, 'author_id' => $comment->author_id,
                        'author_name' => $comment->author?->name, 'body' => $comment->body,
                        'created_at' => $comment->created_at?->toAtomString(),
                    ])->all() ?? [],
                ],
                'participation_exists' => (bool) $tender->getAttribute('team_participation_exists'),
            ];
        });

        $shared = DB::table('team_search_queries')->join('search_queries', 'search_queries.id', '=', 'team_search_queries.search_query_id')
            ->leftJoin('users', 'users.id', '=', 'team_search_queries.shared_by_id')->where('team_search_queries.team_id', $team->id)
            ->where('search_queries.status', '!=', 'deleted')
            ->orderBy('search_queries.name')->get(['search_queries.id', 'search_queries.name', 'team_search_queries.shared_by_id', 'users.name as shared_by_name'])
            ->map(fn ($query): array => [...(array) $query, 'can_remove' => $team->owner_id === $request->user()->id || (int) $query->shared_by_id === $request->user()->id]);
        $available = SearchQuery::query()->where('user_id', $request->user()->id)->where('status', '!=', 'deleted')
            ->whereNotIn('id', DB::table('team_search_queries')->where('team_id', $team->id)->select('search_query_id'))
            ->orderBy('name')->get(['id', 'name']);
        $settings = app(TeamWorkflowService::class)->settings($team);
        $rules = TeamTenderRoutingRule::query()->where('team_id', $team->id)->orderBy('priority')->orderBy('id')->get();

        return Inertia::render('Tenders', [
            ...$scope->props($request->user(), $team),
            'tenderMatches' => $paginator,
            'filters' => ['q' => $search, 'status' => $status, 'tag' => '', 'query_id' => $queryId,
                'assignee_id' => $assigneeId, 'source' => $source, 'sort' => $sort, 'overdue' => $overdue],
            'filterOptions' => ['queries' => $shared->map(fn ($query) => ['id' => $query['id'], 'name' => $query['name']])->values(), 'tags' => []],
            'savedViews' => TenderFeedView::query()->where('team_id', $team->id)->latest()->get(['id', 'user_id', 'name', 'filters'])
                ->map(fn (TenderFeedView $view): array => ['id' => $view->id, 'name' => $view->name, 'filters' => $view->filters,
                    'can_delete' => $view->user_id === $request->user()->id || $team->owner_id === $request->user()->id]),
            'sharedMonitorings' => $shared,
            'availableMonitorings' => $available,
            'workflowSettings' => $settings,
            'routingRules' => $rules,
            'canManageWorkflow' => $team->owner_id === $request->user()->id && $team->archived_at === null,
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
