<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\TenderQuestion;
use App\Models\User;
use App\Services\TeamWorkspaceService;
use App\Services\TenderQuestionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class TenderQuestionController extends Controller
{
    public function __construct(private readonly TenderQuestionService $questions) {}

    public function index(Request $request, Tender $tender): Response
    {
        $user = $this->user($request);
        $team = $this->questions->scope($request, $user, $tender);
        $participation = $this->questions->participation($user, $tender, $team);

        return Inertia::render('TenderQuestions', [
            'tender' => ['id' => $tender->id, 'title' => $tender->title, 'fingerprint' => $this->questions->fingerprint($tender)],
            'questions' => $this->questions->listing($tender, $participation, $team), 'sources' => $this->questions->sources($tender, $participation),
            'topics' => TenderQuestionService::TOPICS, 'teamId' => $team?->id,
            'canEdit' => $team === null || ($team->archived_at === null && app(TeamWorkspaceService::class)->role($user, $team) !== 'viewer'),
        ]);
    }

    public function compose(Request $request, Tender $tender): JsonResponse
    {
        $user = $this->user($request);
        $this->questions->scope($request, $user, $tender, true);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'], 'question_id' => ['nullable', 'integer', 'min:1'],
            'creation_key' => ['required', 'uuid'], 'tender_hash' => ['required', 'string', 'size:64'], 'confirmed' => ['required', 'accepted'],
            'title' => ['required', 'string', 'max:160'], 'topic' => ['required', Rule::in(array_keys(TenderQuestionService::TOPICS))],
            'context' => ['nullable', 'string', 'max:2000'], 'mode' => ['required', Rule::in(['manual', 'card', 'costgap'])],
            'quote' => ['exclude_unless:mode,manual', 'required', 'string', 'max:4000'],
            'source_title' => ['exclude_unless:mode,manual', 'required', 'string', 'max:200'],
            'source_ref' => ['exclude_unless:mode,manual', 'required', 'string', 'max:160'], 'source_url' => ['exclude_unless:mode,manual', 'nullable', 'string', 'url:https', 'max:2000'],
            'fragment' => ['exclude_unless:mode,card', 'required', 'integer', 'min:0', 'max:1000'],
            'cost_key' => ['exclude_unless:mode,costgap', 'required', 'string', 'max:80'],
            'evidence_index' => ['exclude_unless:mode,costgap', 'required', 'integer', 'min:0', 'max:1000'],
            'cost_source_hash' => ['exclude_unless:mode,costgap', 'required', 'string', 'size:64'],
        ]);
        if (isset($data['source_url'])) {
            abort_if(parse_url($data['source_url'], PHP_URL_USER) !== null || parse_url($data['source_url'], PHP_URL_PASS) !== null, 422, 'Ссылка не должна содержать логин или пароль.');
        }
        $hash = hash('sha256', json_encode(array_diff_key($data, array_flip(['creation_key', 'version', 'question_id', 'confirmed'])), JSON_THROW_ON_ERROR));
        [$question, $team] = DB::transaction(function () use ($request, $user, $tender, $data, $hash): array {
            [$actor, $current, $team] = $this->questions->lock($request, $user, $tender);
            $participation = $this->questions->participation($actor, $current, $team, true);
            $question = null;
            if (isset($data['question_id'])) {
                $question = $this->find($participation, $data['question_id'], true);
                $this->version($question, $data);
                abort_if($question->marked_sent_at !== null || $question->archived_at !== null, 409, 'Пересобрать можно только действующий неотправленный черновик.');
            } elseif ($participation !== null) {
                $duplicate = TenderQuestion::query()->where('participation_id', $participation->id)->where('creation_key', $data['creation_key'])->first();
                if ($duplicate !== null) {
                    abort_unless($duplicate->creation_hash === $hash, 409, 'Этот запрос уже создал другой черновик. Обновите страницу перед новым вопросом.');

                    return [$duplicate, $team];
                }
            }
            abort_unless($this->questions->fingerprint($current) === $data['tender_hash'], 409, 'Карточка закупки изменилась. Обновите страницу и проверьте условие.');
            abort_if($question === null && (int) $data['version'] !== 0, 409, 'Для нового вопроса нужна начальная версия.');
            abort_if($question === null && $participation !== null && TenderQuestion::query()->where('participation_id', $participation->id)->count() >= 100, 422, 'В одной заявке допускается до 100 вопросов, включая архив.');
            $source = $this->questions->source($current, $participation, $data);
            $body = $this->questions->compose($current, $source, $data['topic'], $data['context'] ?? null);
            abort_if(mb_strlen($body) > 12000, 422, 'Черновик превышает 12 000 знаков. Сократите цитату или пояснение.');
            $participation ??= TenderParticipation::query()->create(['user_id' => $actor->id, 'team_id' => $team?->id, 'tender_id' => $current->id, 'stage' => 'studying']);
            $created = $question === null;
            $question ??= new TenderQuestion(['participation_id' => $participation->id, 'created_by' => $actor->id,
                'creation_key' => $data['creation_key'], 'creation_hash' => $hash]);
            $question->fill(['title' => $data['title'], 'topic' => $data['topic'], 'context' => $data['context'] ?? null,
                'body' => $body, 'source' => $source, 'tender_hash' => $this->questions->fingerprint($current),
                'version' => (int) $data['version'] + 1, 'source_revision' => $created ? 1 : $question->source_revision + 1, 'updated_by' => $actor->id])->save();
            $question->refresh();
            $this->questions->record($question, $actor, $team, $created ? 'created' : 'recomposed');

            return [$question, $team];
        });

        return $this->response($user, $tender, $team, $question);
    }

    public function update(Request $request, Tender $tender, int $question): JsonResponse
    {
        $this->authorizeWrite($request, $tender);
        $data = $request->validate([...$this->rules(), 'title' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:12000']]);

        return $this->mutate($request, $tender, $question, $data, function (TenderQuestion $row) use ($data): string {
            abort_if($row->marked_sent_at !== null, 409, 'Отправленный текст сохранён в истории. Для новой редакции создайте отдельный вопрос.');
            $row->fill(['title' => $data['title'], 'body' => $data['body']]);

            return 'edited';
        });
    }

    public function markSent(Request $request, Tender $tender, int $question): JsonResponse
    {
        $this->authorizeWrite($request, $tender);
        $data = $request->validate([...$this->rules(), 'sent_reference' => ['nullable', 'string', 'max:500']]);

        return $this->mutate($request, $tender, $question, $data, function (TenderQuestion $row, User $actor, Tender $current, TenderParticipation $participation) use ($data): string {
            abort_if($this->questions->stale($row, $current, $participation, true) !== [], 409, 'Материалы изменились. Обновите основание черновика перед отметкой отправки.');
            $row->fill(['marked_sent_at' => now(), 'marked_sent_by' => $actor->id, 'sent_reference' => $data['sent_reference'] ?? null]);

            return 'marked_sent';
        }, retrySent: true);
    }

    public function answer(Request $request, Tender $tender, int $question): JsonResponse
    {
        $this->authorizeWrite($request, $tender);
        $data = $request->validate([...$this->rules(), 'answer' => ['required', 'string', 'max:10000'],
            'answer_source' => ['required', 'string', 'max:500'], 'answer_received_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Moscow')->toDateString()]]);

        return $this->mutate($request, $tender, $question, $data, function (TenderQuestion $row, User $actor) use ($data): string {
            abort_if($row->marked_sent_at === null, 422, 'Сначала отметьте вопрос как отправленный.');
            $row->fill(['answer' => $data['answer'], 'answer_source' => $data['answer_source'], 'answer_received_on' => $data['answer_received_on'] ?? null,
                'answer_updated_at' => now(), 'answer_updated_by' => $actor->id]);

            return 'answer_saved';
        });
    }

    public function archive(Request $request, Tender $tender, int $question): JsonResponse
    {
        $this->authorizeWrite($request, $tender);
        $data = $request->validate([...$this->rules(), 'archived' => ['required', 'boolean']]);

        return $this->mutate($request, $tender, $question, $data, function (TenderQuestion $row) use ($data): string {
            $row->archived_at = $data['archived'] ? now() : null;

            return $data['archived'] ? 'archived' : 'restored';
        }, allowArchived: true);
    }

    public function export(Request $request, Tender $tender, int $question): HttpResponse
    {
        $user = $this->user($request);
        $team = $this->questions->scope($request, $user, $tender);
        $participation = $this->questions->participation($user, $tender, $team);
        $row = $this->find($participation, $question);
        abort_unless((int) $request->query('version') === $row->version, 409, 'Сохраните и обновите вопрос перед копированием.');
        if ($row->marked_sent_at === null) {
            abort_if($row->archived_at !== null, 409, 'Сначала восстановите черновик из архива.');
            abort_if($this->questions->stale($row, $tender, $participation) !== [], 409, 'Материалы изменились. Обновите основание перед копированием.');
        }
        if ($request->boolean('copy')) {
            return response()->json(['text' => $row->body], headers: ['Cache-Control' => 'private, no-store']);
        }

        return response("\xEF\xBB\xBF".$row->body, 200, ['Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="TenderFinder-question-'.$row->id.'.txt"', 'Cache-Control' => 'private, no-store']);
    }

    public function history(Request $request, Tender $tender, int $question): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->questions->scope($request, $user, $tender);
        $row = $this->find($this->questions->participation($user, $tender, $team), $question);
        $events = DB::table('tender_question_events')->where('question_id', $row->id)->orderByDesc('version')->limit(20)->get()
            ->map(fn ($event): array => ['version' => $event->version, 'action' => $event->action, 'created_at' => $event->created_at,
                'snapshot' => json_decode($event->snapshot, true, flags: JSON_THROW_ON_ERROR)])->all();

        return response()->json(['events' => $events], headers: ['Cache-Control' => 'private, no-store']);
    }

    /** @param array<string, mixed> $data
     * @param  Closure(TenderQuestion, User, Tender, TenderParticipation): string  $change
     */
    private function mutate(Request $request, Tender $tender, int $id, array $data, Closure $change, bool $allowArchived = false, bool $retrySent = false): JsonResponse
    {
        $user = $this->user($request);
        $this->questions->scope($request, $user, $tender, true);
        [$question, $team] = DB::transaction(function () use ($request, $user, $tender, $id, $data, $change, $allowArchived, $retrySent): array {
            [$actor, $current, $team] = $this->questions->lock($request, $user, $tender);
            $participation = $this->questions->participation($actor, $current, $team, true);
            $row = $this->find($participation, $id, true);
            if ($retrySent && $row->marked_sent_at !== null) {
                return [$row, $team];
            }
            $this->version($row, $data);
            abort_if(! $allowArchived && $row->archived_at !== null, 409, 'Сначала восстановите вопрос из архива.');
            $action = $change($row, $actor, $current, $participation);
            $row->version++;
            $row->updated_by = $actor->id;
            $row->save();
            $this->questions->record($row, $actor, $team, $action);

            return [$row, $team];
        });

        return $this->response($user, $tender, $team, $question);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->questions->enabled($user), 403, 'Вопросы заказчику доступны при действующем Pro или Ultra.');

        return $user;
    }

    private function authorizeWrite(Request $request, Tender $tender): void
    {
        $this->questions->scope($request, $this->user($request), $tender, true);
    }

    private function find(?TenderParticipation $participation, int $id, bool $lock = false): TenderQuestion
    {
        abort_if($participation === null, 404);
        $query = TenderQuestion::query()->where('participation_id', $participation->id)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']];
    }

    /** @param array<string, mixed> $data */
    private function version(TenderQuestion $question, array $data): void
    {
        abort_unless($question->version === (int) $data['version'], 409, 'Вопрос изменён в другой вкладке. Обновите страницу; введённые правки остались в форме.');
    }

    private function response(User $user, Tender $tender, ?Team $team, TenderQuestion $question): JsonResponse
    {
        $participation = $this->questions->participation($user, $tender, $team);

        return response()->json(['questions' => $this->questions->listing($tender->refresh(), $participation, $team), 'question_id' => $question->id], headers: ['Cache-Control' => 'private, no-store']);
    }
}
