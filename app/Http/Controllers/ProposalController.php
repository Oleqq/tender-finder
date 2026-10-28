<?php

namespace App\Http\Controllers;

use App\Models\ProposalBlock;
use App\Models\ProposalDraft;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\ProposalService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ProposalController extends Controller
{
    public function __construct(private readonly ProposalService $proposals) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $enabled = $this->proposals->enabled($user);

        return Inertia::render('ProposalLibrary', ['enabled' => $enabled, 'blocks' => $enabled ? $this->proposals->blocks($user) : []]);
    }

    public function saveBlock(Request $request, ?int $block = null): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'], 'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(['services', 'experience', 'works', 'delivery', 'warranty', 'team', 'template'])],
            'body' => ['required', 'string', 'max:8000'], 'confirmed' => ['required', 'accepted'],
        ]);
        abort_if($data['category'] === 'template' && ! str_contains($data['body'], '{{блоки}}'), 422, 'Добавьте {{блоки}} в шаблон, чтобы выбранные материалы попали в предложение.');
        DB::transaction(function () use ($user, $block, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->proposals->enabled($locked), 403);
            $row = $block === null ? new ProposalBlock : ProposalBlock::query()->where('user_id', $locked->id)->findOrFail($block);
            abort_unless(($row->version ?? 0) === (int) $data['version'], 409, 'Блок изменён в другой вкладке. Обновите страницу.');
            abort_if($block === null && ProposalBlock::query()->where('user_id', $locked->id)->count() >= 100, 422, 'В библиотеке допускается до 100 блоков и шаблонов.');
            $row->fill(['user_id' => $locked->id, 'title' => $data['title'], 'category' => $data['category'], 'body' => $data['body'], 'version' => (int) $data['version'] + 1])->save();
        });

        return response()->json(['blocks' => $this->proposals->blocks($user)]);
    }

    public function deleteBlock(Request $request, int $block): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($user, $block, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->proposals->enabled($locked), 403);
            $row = ProposalBlock::query()->where('user_id', $locked->id)->findOrFail($block);
            abort_unless($row->version === (int) $data['version'], 409, 'Блок изменён в другой вкладке.');
            $row->delete();
        });

        return response()->json(['blocks' => $this->proposals->blocks($user)]);
    }

    public function show(Request $request, Tender $tender): Response
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender);

        return Inertia::render('TenderProposal', [
            'blocks' => $this->proposals->blocks($user), 'defaultTemplate' => ProposalService::TEMPLATE,
            'draft' => $this->proposals->present($this->proposals->current($user, $tender, $team), $tender),
            'tender' => ['id' => $tender->id, 'title' => $tender->title, 'fingerprint' => $this->proposals->fingerprint($tender)],
            'teamId' => $team?->id, 'canEdit' => $team === null || ($team->archived_at === null && app(TeamWorkspaceService::class)->role($user, $team) !== 'viewer'),
        ]);
    }

    public function compose(Request $request, Tender $tender): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender, true);
        $data = $request->validate([
            ...$this->draftRules(), 'blocks' => ['present', 'array', 'max:20'],
            'blocks.*' => ['array:id,version'], 'blocks.*.id' => ['required', 'integer', 'distinct', 'min:1'],
            'blocks.*.version' => ['required', 'integer', 'min:1'],
            'template_id' => ['nullable', 'integer', 'min:1'], 'template_version' => ['nullable', 'integer', 'min:1', 'required_with:template_id'],
            'fields' => ['required', 'array:company,contacts,delivery,price'],
            'fields.company' => ['nullable', 'string', 'max:1000'], 'fields.contacts' => ['nullable', 'string', 'max:1000'],
            'fields.delivery' => ['nullable', 'string', 'max:1000'],
            'fields.price' => ['nullable', 'string', 'regex:/^\d{1,12}([.,]\d{1,2})?$/'],
        ]);
        DB::transaction(function () use ($request, $user, $tender, $team, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->proposals->enabled($locked), 403);
            $current = Tender::query()->lockForUpdate()->findOrFail($tender->id);
            $this->authorizeTender($request, $locked, $current, true);
            abort_unless($this->proposals->fingerprint($current) === $data['tender_hash'], 409, 'Карточка тендера изменилась. Обновите страницу.');
            $draft = $this->proposals->current($locked, $current, $team);
            abort_unless(($draft->version ?? 0) === (int) $data['version'], 409, 'Черновик изменён в другой вкладке. Обновите страницу.');
            $result = $this->proposals->compose($locked, $current, $data['blocks'], $data['fields'], $data['template_id'] ?? null, $data['template_version'] ?? null);
            $draft ??= new ProposalDraft;
            $draft->fill([...$result, 'user_id' => $locked->id, 'tender_id' => $current->id, 'team_id' => $team?->id,
                'scope_key' => $this->proposals->scope($team), 'version' => (int) $data['version'] + 1])->save();
        });

        return $this->draftResponse($user, $tender, $team);
    }

    public function save(Request $request, Tender $tender): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender, true);
        $data = $request->validate([...$this->draftRules(), 'body' => ['required', 'string', 'max:50000']]);
        DB::transaction(function () use ($request, $user, $tender, $team, $data): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($this->proposals->enabled($locked), 403);
            $current = Tender::query()->lockForUpdate()->findOrFail($tender->id);
            $this->authorizeTender($request, $locked, $current, true);
            abort_unless($this->proposals->fingerprint($current) === $data['tender_hash'], 409, 'Карточка изменилась. Обновите страницу и проверьте текст.');
            $draft = $this->proposals->current($locked, $current, $team);
            abort_if($draft === null, 404);
            abort_unless($draft->version === (int) $data['version'], 409, 'Черновик изменён в другой вкладке. Обновите страницу.');
            $snapshot = $draft->snapshot;
            $snapshot['tender_hash'] = $this->proposals->fingerprint($current);
            $snapshot['reviewed_at'] = now()->toAtomString();
            $draft->update(['body' => $data['body'], 'snapshot' => $snapshot, 'version' => $draft->version + 1]);
        });

        return $this->draftResponse($user, $tender, $team);
    }

    public function export(Request $request, Tender $tender): HttpResponse
    {
        $user = $this->user($request);
        $team = $this->authorizeTender($request, $user, $tender);
        $draft = $this->proposals->current($user, $tender, $team);
        abort_if($draft === null, 404);
        abort_unless((int) $request->query('version') === $draft->version, 409, 'Сохраните и обновите черновик перед выгрузкой.');
        abort_if($this->proposals->missing($draft->body) !== [], 422, 'Заполните отмеченные поля перед выгрузкой.');
        abort_unless(($draft->snapshot['tender_hash'] ?? '') === $this->proposals->fingerprint($tender), 409, 'Карточка изменилась. Проверьте и сохраните текст.');

        return response("\xEF\xBB\xBF".$draft->body, 200, ['Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="TenderFinder-proposal-'.$tender->id.'.txt"', 'Cache-Control' => 'private, no-store']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->proposals->enabled($user), 403, 'Предложения доступны при действующем Pro или Ultra.');

        return $user;
    }

    private function authorizeTender(Request $request, User $user, Tender $tender, bool $write = false): ?Team
    {
        $team = app(TeamWorkspaceService::class)->context($request);
        if ($team !== null) {
            abort_if(TenderParticipation::onlyTrashed()->where('team_id', $team->id)->where('tender_id', $tender->id)->exists(), 404);
            abort_if($write && $team->archived_at !== null, 409, 'Сначала восстановите команду.');
            abort_if($write && app(TeamWorkspaceService::class)->role($user, $team) === 'viewer', 403);
            $shared = DB::table('tender_query_matches')->where('tender_id', $tender->id)
                ->whereIn('search_query_id', DB::table('team_search_queries')->where('team_id', $team->id)->select('search_query_id'))->exists();
            if (! $shared) {
                abort_unless(TenderParticipation::query()->where('team_id', $team->id)->where('tender_id', $tender->id)->exists(), 404);
            }
        } else {
            app(TenderWorkService::class)->authorize($user, $tender);
            abort_if(TenderParticipation::onlyTrashed()->where('user_id', $user->id)->whereNull('team_id')->where('tender_id', $tender->id)->exists(), 404);
        }

        return $team;
    }

    /** @return array<string, list<string>> */
    private function draftRules(): array
    {
        return ['version' => ['required', 'integer', 'min:0'], 'tender_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['required', 'accepted']];
    }

    private function draftResponse(User $user, Tender $tender, ?Team $team): JsonResponse
    {
        return response()->json(['draft' => $this->proposals->present($this->proposals->current($user, $tender->refresh(), $team), $tender)]);
    }
}
